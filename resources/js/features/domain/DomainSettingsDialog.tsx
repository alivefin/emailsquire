import { useId, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { apiFetch } from '../../api/client';
import { fieldErrors } from '../../api/problems';
import { queryKeys, type Json } from '../../api/types';
import { Button } from '../../components/ui/Button';
import { cx } from '../../components/ui/cx';
import { Dialog } from '../../components/ui/Dialog';
import { QuotaInput, type QuotaValue } from '../../components/ui/QuotaInput';
import { Switch } from '../../components/ui/Switch';
import { TextInput } from '../../components/ui/TextInput';
import { useToast } from '../../components/ui/Toast';
import { formatBytes } from '../../lib/format';
import type { DomainRole } from '../../lib/roles';
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

export type DomainSelfService = Json<'/domains/{domain}/self-service', 'get'>;

type Switch = keyof DomainSelfService;

const SWITCHES: Switch[] = ['forwarding', 'aliases', 'external_forwarding'];
const SWITCH_LABELS: Record<Switch, string> = {
    forwarding: 'settings.selfService.forwarding',
    aliases: 'settings.selfService.aliases',
    external_forwarding: 'settings.selfService.externalForwarding',
};

export function selfServicePath(domain: string): string {
    return `/domains/${encodeURIComponent(domain)}/self-service`;
}

/** What the domain lets its mailbox owners change in the portal. Every role may read it. */
export function useDomainSelfService(domain: string, enabled = true) {
    return useQuery({
        queryKey: queryKeys.domainSelfService(domain),
        queryFn: () => apiFetch<DomainSelfService>(selfServicePath(domain)),
        enabled: enabled && domain !== '',
    });
}

export interface DomainSettingsDialogProps {
    open: boolean;
    onClose: () => void;
    /** Punycode domain name, as the API wants it. */
    domain: string;
    unicode: string;
    /** The requester's role on the domain: an admin also sets the limits, a viewer only reads. */
    role: DomainRole;
}

/**
 * The domain header's settings: the limits (max mailboxes, total storage) for server admins, and what the
 * domain's mailbox owners can change in the portal. Admins and managers set those switches; a viewer sees
 * them read-only. One Save sends whichever of the two changed.
 */
export function DomainSettingsDialog({ open, onClose, domain, unicode, role }: DomainSettingsDialogProps) {
    const { t } = useTranslation('domains');
    const admin = role === 'admin';
    const settings = useDomainSettings(domain, open && admin);
    const switches = useDomainSelfService(domain, open);
    const limitsFailed = admin && settings.isError;
    const failed = limitsFailed ? settings.error : switches.isError ? switches.error : null;
    const ready = switches.data !== undefined && (!admin || settings.data !== undefined);

    function retry() {
        if (settings.isError) void settings.refetch();
        if (switches.isError) void switches.refetch();
    }

    return (
        <Dialog open={open} onClose={onClose} title={t('settings.title')} subtitle={unicode}>
            {ready && switches.data ? (
                // Mounted once everything is known, so the fields start from it.
                <Form
                    settings={admin ? (settings.data ?? null) : null}
                    switches={switches.data}
                    readOnly={role === 'viewer'}
                    domain={domain}
                    onClose={onClose}
                />
            ) : failed !== null ? (
                <div role="alert" className="flex items-center gap-3 text-[13px] text-danger">
                    <span className="min-w-0 flex-1">{errorText(failed, limitsFailed ? t('settings.loadFailed') : t('settings.loadFailedAll'))}</span>
                    <Button size="sm" onClick={retry}>
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

interface FormProps {
    /** The limits, for a server admin; null hides them. */
    settings: DomainSettings | null;
    switches: DomainSelfService;
    readOnly: boolean;
    domain: string;
    onClose: () => void;
}

function Form({ settings, switches, readOnly, domain, onClose }: FormProps) {
    const { t } = useTranslation('domains');
    const queryClient = useQueryClient();
    const toast = useToast();
    const once = useSingleFlight();
    const formId = useId();
    const groupId = useId();
    const externalHintId = useId();

    const [countText, setCountText] = useState(settings?.max_mailboxes == null ? '' : String(settings.max_mailboxes));
    const [quota, setQuota] = useState<QuotaValue>({ bytes: settings?.max_quota_bytes_total ?? null, problem: null });
    const [owners, setOwners] = useState<DomainSelfService>(switches);
    const [errors, setErrors] = useState<{ max_mailboxes?: string; max_quota_bytes_total?: string; form?: string }>({});
    const [busy, setBusy] = useState(false);

    const count = parseCount(countText);
    const countProblem = count === undefined ? t('settings.mailboxesInvalid') : undefined;
    const total = quota.problem === null ? quota.bytes : undefined;
    const limitsValid = settings === null || (count !== undefined && total !== undefined);
    const limitsChanged = settings !== null && (count !== settings.max_mailboxes || total !== settings.max_quota_bytes_total);
    // Only the switches that really changed are sent: the server audits each as `{field: [old, new]}`.
    const switchChanges = Object.fromEntries(SWITCHES.filter((k) => owners[k] !== switches[k]).map((k) => [k, owners[k]]));
    const switchesChanged = Object.keys(switchChanges).length > 0;
    const changed = limitsChanged || switchesChanged;

    async function saveAll() {
        setBusy(true);
        setErrors({});
        try {
            if (limitsChanged && count !== undefined && total !== undefined) {
                try {
                    const saved = await apiFetch<DomainSettings>(settingsPath(domain), {
                        method: 'PATCH',
                        json: { max_mailboxes: count, max_quota_bytes_total: total },
                    });
                    queryClient.setQueryData(queryKeys.domainSettings(domain), saved);
                } catch (e) {
                    const fields = fieldErrors(e);
                    const mine = { max_mailboxes: fields.max_mailboxes, max_quota_bytes_total: fields.max_quota_bytes_total };
                    setErrors(mine.max_mailboxes || mine.max_quota_bytes_total ? mine : { form: errorText(e, t('settings.failed')) });
                    return;
                }
            }
            if (switchesChanged) {
                try {
                    const saved = await apiFetch<DomainSelfService>(selfServicePath(domain), { method: 'PATCH', json: switchChanges });
                    queryClient.setQueryData(queryKeys.domainSelfService(domain), saved);
                } catch (e) {
                    setErrors({ form: errorText(e, t('settings.failedAll')) });
                    // Limits saved just before stay saved: the header and lists pick them up.
                    if (limitsChanged) void queryClient.invalidateQueries({ queryKey: ['domains'] });
                    return;
                }
            }
            toast.show({ kind: 'success', text: switchesChanged ? t('settings.savedAll') : t('settings.saved') });
            onClose();
            // The header's usage text and the domains list carry the limits too; the activity log the change.
            await Promise.all([
                queryClient.invalidateQueries({ queryKey: ['domains'] }),
                queryClient.invalidateQueries({ queryKey: ['audit-log'] }),
            ]);
        } finally {
            setBusy(false);
        }
    }

    function submit() {
        if (readOnly || !limitsValid || !changed) return;
        once(saveAll);
    }

    function setOwner(key: Switch, on: boolean) {
        setOwners((o) => ({ ...o, [key]: on }));
        setErrors((x) => (x.form ? { ...x, form: undefined } : x));
    }

    const belowMailboxes = settings !== null && typeof count === 'number' && count < settings.usage.mailboxes;
    const belowQuota = settings !== null && typeof total === 'number' && total < settings.usage.quota_bytes_allocated;

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
            {settings !== null && (
                <>
                    <p className="text-[12.5px] text-muted">
                        {t('settings.inUse', {
                            mailboxes: t('mailboxCount', { count: settings.usage.mailboxes }),
                            allocated: formatBytes(settings.usage.quota_bytes_allocated),
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
                                {t('settings.belowMailboxes', { count: settings.usage.mailboxes })}
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
                                setErrors((x) =>
                                    x.max_quota_bytes_total || x.form ? { ...x, max_quota_bytes_total: undefined, form: undefined } : x,
                                );
                            }}
                        />
                        {errors.max_quota_bytes_total && (
                            <p role="alert" className="mt-1 text-[12.5px] text-danger">
                                {errors.max_quota_bytes_total}
                            </p>
                        )}
                        {belowQuota && (
                            <p role="status" className="mt-1 text-[12.5px] text-warn">
                                {t('settings.belowQuota', { allocated: formatBytes(settings.usage.quota_bytes_allocated) })}
                            </p>
                        )}
                    </div>
                </>
            )}
            <div
                role="group"
                aria-labelledby={groupId}
                className={cx('flex flex-col gap-3', settings !== null && 'border-t border-line pt-4')}
            >
                <p id={groupId} className="text-[13px] font-semibold text-ink">
                    {t('settings.selfService.title')}
                </p>
                {SWITCHES.map((key) => {
                    // External forwarding means nothing while owners can't forward at all.
                    const needsForwarding = key === 'external_forwarding' && !owners.forwarding;
                    return (
                        <div key={key}>
                            <Switch
                                label={t(SWITCH_LABELS[key])}
                                checked={owners[key]}
                                disabled={readOnly || needsForwarding}
                                onChange={(on) => setOwner(key, on)}
                                aria-describedby={needsForwarding ? externalHintId : undefined}
                                className="min-h-11 md:min-h-8"
                            />
                            {needsForwarding && (
                                <p id={externalHintId} className="ml-[46px] text-[12.5px] text-muted">
                                    {t('settings.selfService.externalNeedsForwarding')}
                                </p>
                            )}
                        </div>
                    );
                })}
                {readOnly && <p className="text-[12.5px] text-muted">{t('settings.selfService.readOnly')}</p>}
            </div>
            {errors.form && (
                <p role="alert" className="rounded-lg border border-danger-line bg-danger-bg px-3 py-2.5 text-[13px] text-danger">
                    {errors.form}
                </p>
            )}
            <div className="flex justify-end gap-2">
                {readOnly ? (
                    <Button onClick={onClose}>{t('settings.close')}</Button>
                ) : (
                    <>
                        <Button onClick={onClose}>{t('settings.cancel')}</Button>
                        <Button type="submit" variant="dark" disabled={!limitsValid || !changed} busy={busy}>
                            {t('settings.save')}
                        </Button>
                    </>
                )}
            </div>
        </form>
    );
}
