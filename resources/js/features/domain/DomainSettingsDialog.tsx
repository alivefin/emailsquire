import { useId, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { apiFetch } from '../../api/client';
import { fieldErrors } from '../../api/problems';
import { queryKeys, type Json } from '../../api/types';
import { Button } from '../../components/ui/Button';
import { Dialog } from '../../components/ui/Dialog';
import { QuotaInput, type QuotaValue } from '../../components/ui/QuotaInput';
import { TextInput } from '../../components/ui/TextInput';
import { useToast } from '../../components/ui/Toast';
import { formatBytes } from '../../lib/format';
import { errorText, useSingleFlight } from './shared';

export type DomainSettings = Json<'/domains/{domain}/settings', 'get'>;

/** The server's bounds (UpdateDomainSettingsRequest): at most 100 000 mailboxes and 1 PiB in total. */
const MAX_MAILBOXES = 100000;
const MAX_QUOTA_TOTAL = 1125899906842624;
const TOTAL_PRESETS = [10, 50, 100];

export function settingsPath(domain: string): string {
    return `/domains/${encodeURIComponent(domain)}/settings`;
}

/** A domain's limits and use. Every role may read them; only a server admin may change them. */
export function useDomainSettings(domain: string, enabled = true) {
    return useQuery({
        queryKey: queryKeys.domainSettings(domain),
        queryFn: () => apiFetch<DomainSettings>(settingsPath(domain)),
        enabled: enabled && domain !== '',
    });
}

export interface DomainSettingsDialogProps {
    open: boolean;
    onClose: () => void;
    /** Punycode domain name, as the API wants it. */
    domain: string;
    unicode: string;
}

/** The domain limits (max mailboxes, total storage), for server admins from the domain header. */
export function DomainSettingsDialog({ open, onClose, domain, unicode }: DomainSettingsDialogProps) {
    const { t } = useTranslation('domains');
    const settings = useDomainSettings(domain, open);

    return (
        <Dialog open={open} onClose={onClose} title={t('settings.title')} subtitle={unicode}>
            {settings.data ? (
                // Mounted once the limits are known, so the fields start from them.
                <Form settings={settings.data} domain={domain} onClose={onClose} />
            ) : settings.isError ? (
                <div role="alert" className="flex items-center gap-3 text-[13px] text-danger">
                    <span className="min-w-0 flex-1">{errorText(settings.error, t('settings.loadFailed'))}</span>
                    <Button size="sm" onClick={() => void settings.refetch()}>
                        {t('settings.retry')}
                    </Button>
                </div>
            ) : (
                <p aria-busy="true" className="text-[13px] text-muted">
                    {t('settings.loading')}
                </p>
            )}
        </Dialog>
    );
}

/** Null for empty (no limit), the count when valid, undefined when the text is not a valid count. */
function parseCount(text: string): number | null | undefined {
    const trimmed = text.trim();
    if (trimmed === '') return null;
    if (!/^\d+$/.test(trimmed)) return undefined;
    const n = Number(trimmed);
    return n >= 1 && n <= MAX_MAILBOXES ? n : undefined;
}

function Form({ settings, domain, onClose }: { settings: DomainSettings; domain: string; onClose: () => void }) {
    const { t } = useTranslation('domains');
    const queryClient = useQueryClient();
    const toast = useToast();
    const once = useSingleFlight();
    const formId = useId();
    const { usage } = settings;

    const [countText, setCountText] = useState(settings.max_mailboxes === null ? '' : String(settings.max_mailboxes));
    const [quota, setQuota] = useState<QuotaValue>({ bytes: settings.max_quota_bytes_total, problem: null });
    const [errors, setErrors] = useState<{ max_mailboxes?: string; max_quota_bytes_total?: string; form?: string }>({});

    const count = parseCount(countText);
    const countProblem = count === undefined ? t('settings.mailboxesInvalid') : undefined;
    const total = quota.problem === null ? quota.bytes : undefined;
    const valid = count !== undefined && total !== undefined;
    const changed = count !== settings.max_mailboxes || total !== settings.max_quota_bytes_total;

    const save = useMutation({
        mutationFn: (json: { max_mailboxes: number | null; max_quota_bytes_total: number | null }) =>
            apiFetch<DomainSettings>(settingsPath(domain), { method: 'PATCH', json }),
        onSuccess: async (saved) => {
            queryClient.setQueryData(queryKeys.domainSettings(domain), saved);
            toast.show({ kind: 'success', text: t('settings.saved') });
            onClose();
            // The header's usage text and the domains list carry the limits too.
            await queryClient.invalidateQueries({ queryKey: ['domains'] });
        },
        onError: (e) => {
            const fields = fieldErrors(e);
            const mine = { max_mailboxes: fields.max_mailboxes, max_quota_bytes_total: fields.max_quota_bytes_total };
            setErrors(mine.max_mailboxes || mine.max_quota_bytes_total ? mine : { form: errorText(e, t('settings.failed')) });
        },
    });

    function submit() {
        if (!valid || !changed || count === undefined || total === undefined) return;
        setErrors({});
        once(() => save.mutateAsync({ max_mailboxes: count, max_quota_bytes_total: total }));
    }

    const belowMailboxes = typeof count === 'number' && count < usage.mailboxes;
    const belowQuota = typeof total === 'number' && total < usage.quota_bytes_allocated;

    return (
        <form
            id={formId}
            noValidate
            className="flex flex-col gap-4"
            onSubmit={(e) => {
                e.preventDefault();
                submit();
            }}
        >
            <p className="text-[12.5px] text-muted">
                {t('settings.inUse', {
                    mailboxes: t('mailboxCount', { count: usage.mailboxes }),
                    allocated: formatBytes(usage.quota_bytes_allocated),
                })}
            </p>
            <div>
                <TextInput
                    label={t('settings.maxMailboxes')}
                    inputMode="numeric"
                    autoComplete="off"
                    value={countText}
                    onChange={(e) => {
                        setCountText(e.target.value);
                        setErrors((x) => ({ ...x, max_mailboxes: undefined, form: undefined }));
                    }}
                    error={countProblem ?? errors.max_mailboxes}
                    hint={t('settings.unlimitedHint')}
                />
                {belowMailboxes && !countProblem && (
                    <p role="status" className="mt-1 text-[12.5px] text-warn">
                        {t('settings.belowMailboxes', { count: usage.mailboxes })}
                    </p>
                )}
            </div>
            <div>
                <QuotaInput
                    label={t('settings.totalQuota')}
                    optional
                    defaultBytes={settings.max_quota_bytes_total}
                    maxBytes={MAX_QUOTA_TOTAL}
                    presets={TOTAL_PRESETS}
                    hint={t('settings.unlimitedHint')}
                    onChange={(v) => {
                        setQuota(v);
                        setErrors((x) => (x.max_quota_bytes_total || x.form ? { ...x, max_quota_bytes_total: undefined, form: undefined } : x));
                    }}
                />
                {errors.max_quota_bytes_total && (
                    <p role="alert" className="mt-1 text-[12.5px] text-danger">
                        {errors.max_quota_bytes_total}
                    </p>
                )}
                {belowQuota && (
                    <p role="status" className="mt-1 text-[12.5px] text-warn">
                        {t('settings.belowQuota', { allocated: formatBytes(usage.quota_bytes_allocated) })}
                    </p>
                )}
            </div>
            {errors.form && (
                <p role="alert" className="rounded-lg border border-danger-line bg-danger-bg px-3 py-2.5 text-[13px] text-danger">
                    {errors.form}
                </p>
            )}
            <div className="flex justify-end gap-2">
                <Button onClick={onClose}>{t('settings.cancel')}</Button>
                <Button type="submit" variant="dark" disabled={!valid || !changed} busy={save.isPending}>
                    {t('settings.save')}
                </Button>
            </div>
        </form>
    );
}
