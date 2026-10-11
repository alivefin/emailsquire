import { useEffect, useId, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import i18next from 'i18next';
import { Trans, useTranslation } from 'react-i18next';
import { apiFetch } from '../../api/client';
import { fieldErrors } from '../../api/problems';
import { queryKeys, type Json } from '../../api/types';
import { AddonInput } from '../../components/ui/AddonInput';
import { Button } from '../../components/ui/Button';
import { Drawer } from '../../components/ui/Drawer';
import { PasswordField } from '../../components/ui/PasswordField';
import { QuotaInput, type QuotaValue } from '../../components/ui/QuotaInput';
import { Select } from '../../components/ui/Select';
import { TextInput } from '../../components/ui/TextInput';
import { useToast } from '../../components/ui/Toast';
import { formatBytes } from '../../lib/format';
import { scorePassword } from '../../lib/passwordStrength';
import { canWrite } from '../../lib/roles';
import { currentLocale, type Locale } from '../../i18n';
import { useDomainNames } from '../../layout/useNavData';
import { useDomainSettings, type DomainSettings } from './DomainSettingsDialog';
import { postWelcome, RecipientFields, welcomeFailure } from './SendWelcomeDialog';
import {
    displayAddress,
    errorText,
    mailboxesPath,
    refreshAfterMailboxWrite,
    useDebounced,
    useForgetOnPageHide,
    useSingleFlight,
    type Mailbox,
} from './shared';

type AddressCheck = Json<'/domains/{domain}/addresses/check', 'get'>;

export interface NewMailboxDrawerProps {
    open: boolean;
    onClose: () => void;
    /** Punycode domain name. Without it, as from the dashboard, the drawer asks for the domain first. */
    domain?: string;
}

const CHECK_DELAY = 300;
const CHECK_MARK = '✓';
const FIELDS = ['local', 'display_name', 'password', 'quota_bytes'] as const;
type FieldName = (typeof FIELDS)[number];

/** What the "shown once" panel needs. The password lives here and nowhere else. */
interface Created {
    address: string;
    password: string;
    quotaBytes: number;
}

/** "3 mailboxes left · 12 GB left" for the limits the domain has; null when it has none. */
function allowanceText(settings: DomainSettings | undefined): string | null {
    if (!settings) return null;
    const t = (key: string, options: Record<string, unknown>) => i18next.t(key, { ...options, ns: 'mailboxes' });
    const parts: string[] = [];
    if (settings.max_mailboxes !== null) {
        parts.push(t('new.mailboxesLeft', { count: Math.max(0, settings.max_mailboxes - settings.usage.mailboxes) }));
    }
    if (settings.max_quota_bytes_total !== null) {
        const left = Math.max(0, settings.max_quota_bytes_total - settings.usage.quota_bytes_allocated);
        parts.push(t('new.quotaLeft', { amount: formatBytes(left) }));
    }
    return parts.length === 0 ? null : parts.join(' · ');
}

export function NewMailboxDrawer({ open, ...rest }: NewMailboxDrawerProps) {
    // Mounting the body only while open drops every field and the shown-once password on close.
    return open ? <Body {...rest} /> : null;
}

function Body({ onClose, domain }: Omit<NewMailboxDrawerProps, 'open'>) {
    const { t } = useTranslation('mailboxes');
    const queryClient = useQueryClient();
    const toast = useToast();
    const once = useSingleFlight();
    const names = useDomainNames();
    const formId = useId();
    const addressId = useId();
    const passwordMsgId = useId();
    const copyId = useId();

    const [picked, setPicked] = useState('');
    const [local, setLocal] = useState('');
    const [displayName, setDisplayName] = useState('');
    const [password, setPassword] = useState('');
    const [quota, setQuota] = useState<QuotaValue>({ bytes: null, problem: null });
    const [errors, setErrors] = useState<Partial<Record<FieldName, string>>>({});
    const [formError, setFormError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [created, setCreated] = useState<Created | null>(null);
    const [copied, setCopied] = useState(false);
    // The optional setup-instructions mail. The recipient lives in this component's state and nowhere else.
    const [welcome, setWelcome] = useState(false);
    const [welcomeTo, setWelcomeTo] = useState('');
    const [welcomeLocale, setWelcomeLocale] = useState<Locale>(currentLocale);
    const [welcomeResult, setWelcomeResult] = useState<{ sent: string } | { failed: string } | null>(null);
    // Bumped by "Add another" to remount the inputs that keep their own state (quota, Show).
    const [round, setRound] = useState(0);

    // Opened without a domain (the dashboard), it offers only the domains the user may write to.
    const choices = (names.data ?? []).filter((d) => canWrite(d.role));
    const domainName = domain ?? (picked || choices[0]?.name || '');
    const unicode = names.data?.find((d) => d.name === domainName)?.unicode_name ?? domainName;
    const settings = useDomainSettings(domainName);
    const allowance = allowanceText(settings.data);

    const typed = local.trim();
    const debounced = useDebounced(typed, CHECK_DELAY);
    const check = useQuery({
        queryKey: queryKeys.addressCheck(domainName, debounced),
        queryFn: () =>
            apiFetch<AddressCheck>(`/domains/${encodeURIComponent(domainName)}/addresses/check`, { query: { local: debounced } }),
        enabled: domainName !== '' && debounced !== '',
    });
    const checking = typed !== '' && (debounced !== typed || check.isPending);
    const result = typed !== '' && !checking ? check.data : undefined;
    const available = result?.status === 'available';

    const score = scorePassword(password);
    const quotaBytes = quota.problem === null ? quota.bytes : null;
    const canSubmit = domainName !== '' && available && score >= 3 && quotaBytes !== null && (!welcome || welcomeTo.trim() !== '');

    useEffect(() => {
        if (created) document.getElementById(copyId)?.focus();
    }, [created, copyId]);

    // Closing unmounts this body and the password with it.
    useForgetOnPageHide(created !== null, onClose);

    function clearError(field: FieldName) {
        setErrors((e) => (e[field] === undefined ? e : { ...e, [field]: undefined }));
    }

    async function create(bytes: number) {
        setBusy(true);
        setErrors({});
        setFormError(null);
        try {
            const name = displayName.trim();
            const mailbox = await apiFetch<Mailbox>(mailboxesPath(domainName), {
                method: 'POST',
                json: { local: typed, display_name: name === '' ? null : name, password, quota_bytes: bytes },
            });
            const address = displayAddress(mailbox.local, unicode);
            setCreated({ address, password, quotaBytes: mailbox.quota_bytes });
            setPassword('');
            setCopied(false);
            toast.show({ kind: 'success', text: t('new.created', { address }) });
            void refreshAfterMailboxWrite(queryClient);
            if (welcome) await sendWelcome(mailbox.local, welcomeTo.trim(), welcomeLocale);
        } catch (e) {
            const fields = fieldErrors(e);
            const mine: Partial<Record<FieldName, string>> = {};
            for (const f of FIELDS) if (fields[f] !== undefined) mine[f] = fields[f];
            setErrors(mine);
            if (Object.keys(mine).length === 0) setFormError(errorText(e, t('new.failed')));
        } finally {
            setBusy(false);
        }
    }

    /** The mailbox already exists by now, so a failure here is a warning in the created state, not an error. */
    async function sendWelcome(mailboxLocal: string, to: string, locale: Locale) {
        try {
            await postWelcome(domainName, mailboxLocal, to, locale);
            void queryClient.invalidateQueries({ queryKey: ['audit-log'] });
            setWelcomeResult({ sent: to });
        } catch (e) {
            const failure = welcomeFailure(e);
            setWelcomeResult({ failed: failure.to ?? failure.locale ?? failure.form ?? t('welcome.failed') });
        }
    }

    function submit() {
        if (!canSubmit) return;
        once(() => create(quotaBytes));
    }

    function addAnother() {
        setCreated(null);
        setCopied(false);
        setLocal('');
        setDisplayName('');
        setPassword('');
        setWelcome(false);
        setWelcomeTo('');
        setWelcomeLocale(currentLocale());
        setWelcomeResult(null);
        setErrors({});
        setFormError(null);
        setRound((r) => r + 1);
        // The address input is back on the next render.
        setTimeout(() => document.getElementById(addressId)?.focus(), 0);
    }

    function copy(text: string) {
        // Through a promise so a missing clipboard (plain http) lands in the failure branch too.
        Promise.resolve()
            .then(() => navigator.clipboard.writeText(text))
            .then(
                () => setCopied(true),
                () => toast.show({ kind: 'danger', text: t('password.copyFailed') }),
            );
    }

    const subtitle = unicode === '' ? undefined : t('breadcrumb', { domain: unicode });
    // Closing while the create is pending would lose the shown-once password, so every close path
    // (Cancel, ×, Escape, backdrop) waits for the answer.
    const close = busy ? () => {} : onClose;

    if (created) {
        return (
            <Drawer open onClose={onClose} title={t('new.createdTitle')} subtitle={subtitle}>
                <div className="flex min-h-full flex-col gap-4 text-[13.5px]">
                    <div className="flex gap-2.5 rounded-lg border border-teal/30 bg-tint px-3.5 py-3">
                        <span aria-hidden="true" className="font-extrabold text-teal-dark">
                            {CHECK_MARK}
                        </span>
                        <span className="wrap-anywhere">
                            <Trans
                                t={t}
                                i18nKey="new.createdMessage"
                                values={{ address: created.address, limit: formatBytes(created.quotaBytes) }}
                                components={{ b: <b /> }}
                            />
                        </span>
                    </div>
                    {welcomeResult && 'sent' in welcomeResult && (
                        <p role="status" className="text-[12.5px] text-teal-dark wrap-anywhere">
                            {t('welcome.sent', { to: welcomeResult.sent })}
                        </p>
                    )}
                    {welcomeResult && 'failed' in welcomeResult && (
                        <p role="alert" className="rounded-lg border border-warn-line bg-warn-bg px-3 py-2.5 text-[12.5px] text-warn wrap-anywhere">
                            {t('welcome.createdNotSent', { reason: welcomeResult.failed })}
                        </p>
                    )}
                    <div className="rounded-[10px] border border-warn-line bg-warn-bg p-4">
                        <div className="text-xs font-bold tracking-[.06em] text-warn uppercase">{t('password.shownOnce')}</div>
                        <div className="mt-2.5 flex gap-2 max-md:flex-col">
                            <div className="flex h-11 min-w-0 flex-1 items-center overflow-x-auto rounded-lg border border-line bg-white px-3 font-mono text-[15px] whitespace-nowrap select-all max-md:h-12 max-md:text-base">
                                {created.password}
                            </div>
                            <Button id={copyId} variant="dark" size="lg" className="h-11 min-w-[84px]" onClick={() => copy(created.password)}>
                                {copied ? t('password.copied') : t('password.copy')}
                            </Button>
                        </div>
                        <p className="mt-2.5 text-[12.5px] leading-normal text-body">
                            {t('password.giveToOwner')}
                        </p>
                    </div>
                    <div className="mt-auto flex flex-col gap-2">
                        <Button variant="primary" size="lg" className="h-11" onClick={onClose}>
                            {t('password.done')}
                        </Button>
                        <Button size="lg" className="h-11" onClick={addAnother}>
                            {t('new.addAnother')}
                        </Button>
                    </div>
                </div>
            </Drawer>
        );
    }

    const checkFailed = typed !== '' && !checking && check.isError;
    const addressError =
        errors.local ??
        (result && !available ? result.message : undefined) ??
        (checkFailed ? errorText(check.error, t('new.checkFailed')) : undefined);

    return (
        <Drawer
            open
            onClose={close}
            closeDisabled={busy}
            title={t('new.title')}
            subtitle={subtitle}
            footer={
                <>
                    <Button size="lg" disabled={busy} onClick={close}>
                        {t('new.cancel')}
                    </Button>
                    <div className="flex-1" />
                    <Button type="submit" form={formId} variant="primary" size="lg" className="min-w-[150px] max-md:flex-1" disabled={!canSubmit} busy={busy}>
                        {t('new.create')}
                    </Button>
                </>
            }
        >
            <form
                id={formId}
                noValidate
                className="flex flex-col gap-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    submit();
                }}
            >
                {domain === undefined && (
                    <Select label={t('new.domain')} value={domainName} disabled={!names.data} onChange={(e) => setPicked(e.target.value)}>
                        {choices.map((d) => (
                            <option key={d.name} value={d.name}>
                                {d.unicode_name}
                            </option>
                        ))}
                    </Select>
                )}
                {allowance && (
                    <p role="status" className="rounded-lg border border-line bg-subtle px-3 py-2 text-[12.5px] text-body">
                        {allowance}
                    </p>
                )}
                <div>
                    <AddonInput
                        id={addressId}
                        label={t('new.address')}
                        domain={unicode}
                        placeholder={t('new.addressPlaceholder')}
                        autoComplete="off"
                        value={local}
                        onChange={(e) => {
                            setLocal(e.target.value);
                            clearError('local');
                        }}
                        error={addressError}
                        hint={checking ? t('new.checking') : undefined}
                        aria-describedby={addressError || checking ? `${addressId}-msg` : available ? `${addressId}-ok` : undefined}
                    />
                    {available && !addressError && (
                        <p id={`${addressId}-ok`} role="status" className="mt-1 text-[12.5px] text-teal-dark">
                            {result.message}
                        </p>
                    )}
                </div>
                <TextInput
                    label={t('new.displayName')}
                    value={displayName}
                    maxLength={100}
                    onChange={(e) => {
                        setDisplayName(e.target.value);
                        clearError('display_name');
                    }}
                    error={errors.display_name}
                />
                <div>
                    <PasswordField
                        key={`pw-${round}`}
                        secretKind="mailbox"
                        value={password}
                        onChange={(v) => {
                            setPassword(v);
                            clearError('password');
                        }}
                        aria-invalid={errors.password ? true : undefined}
                        aria-describedby={errors.password ? passwordMsgId : undefined}
                    />
                    {errors.password && (
                        <p id={passwordMsgId} role="alert" className="mt-1 text-[12.5px] text-danger">
                            {errors.password}
                        </p>
                    )}
                </div>
                <div>
                    <QuotaInput key={`quota-${round}`} label={t('new.storageLimit')} onChange={setQuota} />
                    {errors.quota_bytes && (
                        <p role="alert" className="mt-1 text-[12.5px] text-danger">
                            {errors.quota_bytes}
                        </p>
                    )}
                </div>
                <label className="flex items-start gap-2.5 text-[13px] text-body">
                    <input
                        type="checkbox"
                        checked={welcome}
                        onChange={(e) => setWelcome(e.target.checked)}
                        className="mt-0.5 size-4 flex-none accent-teal"
                    />
                    <span>{t('welcome.option')}</span>
                </label>
                {welcome && <RecipientFields to={welcomeTo} locale={welcomeLocale} onTo={setWelcomeTo} onLocale={setWelcomeLocale} />}
                {formError && (
                    <p role="alert" className="rounded-lg border border-danger-line bg-danger-bg px-3 py-2.5 text-[13px] text-danger">
                        {formError}
                    </p>
                )}
            </form>
        </Drawer>
    );
}
