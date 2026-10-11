import { useEffect, useId, useState } from 'react';
import { Link } from 'react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { apiFetch } from '../../api/client';
import { ApiProblem, fieldErrors } from '../../api/problems';
import { queryKeys, type Json } from '../../api/types';
import { Button } from '../../components/ui/Button';
import { Drawer } from '../../components/ui/Drawer';
import { QuotaInput, type QuotaValue } from '../../components/ui/QuotaInput';
import { StorageBar } from '../../components/ui/StorageBar';
import { Switch } from '../../components/ui/Switch';
import { TextInput } from '../../components/ui/TextInput';
import { useToast } from '../../components/ui/Toast';
import { formatBytes, formatRelative } from '../../lib/format';
import { canWrite } from '../../lib/roles';
import { useDomainNames } from '../../layout/useNavData';
import { useMe } from '../auth/useMe';
import { DeleteMailboxDialog } from './DeleteMailboxDialog';
import { ForwardingDrawer } from './ForwardingDrawer';
import { SendWelcomeDialog } from './SendWelcomeDialog';
import { displayAddress, errorText, mailboxesPath, refreshAfterMailboxWrite, useForgetOnPageHide, useSingleFlight, type Mailbox } from './shared';

type Usage = Json<'/domains/{domain}/mailboxes/{mailbox}/usage', 'get'>;
type ForwardingList = Json<'/domains/{domain}/forwarding', 'get'>;
type NewPassword = Json<'/domains/{domain}/mailboxes/{mailbox}/password', 'post'>;

export interface EditMailboxDrawerProps {
    open: boolean;
    onClose: () => void;
    /** Punycode domain name, as the API wants it. */
    domain: string;
    local: string;
    /** `password` scrolls to the password section and focuses its button, for "Reset password". */
    focus?: 'password';
}

const sectionTitle = 'mb-1.5 text-[13px] font-semibold text-ink';

export function EditMailboxDrawer({ open, ...rest }: EditMailboxDrawerProps) {
    // Mounting the body only while open, keyed by the mailbox, drops the shown-once password on close
    // and when another mailbox opens.
    return open ? <Body key={`${rest.domain}/${rest.local}`} {...rest} /> : null;
}

function Body({ onClose, domain, local, focus }: Omit<EditMailboxDrawerProps, 'open'>) {
    const { t } = useTranslation('mailboxes');
    const names = useDomainNames();
    const named = names.data?.find((d) => d.name === domain);
    const unicode = named?.unicode_name ?? domain;
    // A viewer gets a read-only detail; the role must be known before either form mounts.
    const readOnly = !canWrite(named?.role);
    const path = mailboxesPath(domain, local);
    const address = displayAddress(local, unicode);

    const mailbox = useQuery({
        queryKey: queryKeys.mailbox(domain, local),
        queryFn: () => apiFetch<Mailbox>(path),
    });

    // The password panel lives here, not in a mutation, so the plaintext stays in this component's state.
    const [password, setPassword] = useState<string | null>(null);
    const [passwordBusy, setPasswordBusy] = useState(false);
    const close = passwordBusy ? () => {} : onClose;
    useForgetOnPageHide(password !== null, () => setPassword(null));

    const m = mailbox.data;
    const subtitle = t('edit.subtitle', { name: m?.display_name || t('edit.noDisplayName'), domain: unicode });

    return (
        <Drawer open onClose={close} closeDisabled={passwordBusy} title={address} subtitle={m ? subtitle : undefined}>
            {m && !names.isPending && readOnly ? (
                <ReadOnlyDetail m={m} domain={domain} unicode={unicode} path={path} />
            ) : m && !names.isPending ? (
                <Editor
                    m={m}
                    domain={domain}
                    unicode={unicode}
                    path={path}
                    address={address}
                    focus={focus}
                    password={password}
                    setPassword={setPassword}
                    passwordBusy={passwordBusy}
                    setPasswordBusy={setPasswordBusy}
                    onClose={onClose}
                />
            ) : mailbox.isError ? (
                <p role="alert" className="rounded-lg border border-danger-line bg-danger-bg px-3 py-2.5 text-[13px] text-danger">
                    {errorText(mailbox.error, t('edit.loadFailed'))}
                </p>
            ) : (
                <p aria-busy="true" className="text-[13px] text-muted">
                    {t('edit.loading')}
                </p>
            )}
        </Drawer>
    );
}

/** The live usage (doveadm), with the list's figure standing in while it is unavailable. */
function useLiveUsage(m: Mailbox, domain: string, path: string) {
    const usage = useQuery({
        queryKey: queryKeys.usage(domain, m.local),
        queryFn: () => apiFetch<Usage>(`${path}/usage`),
        // A 503 means doveadm could not answer; the list's figure stands in at once instead of after retries.
        retry: false,
    });
    const live = usage.data;
    return {
        usage,
        used: live ? live.used_bytes : m.used_bytes,
        checkedAt: live ? live.checked_at : m.usage_checked_at,
    };
}

function StorageSection({ m, live }: { m: Mailbox; live: ReturnType<typeof useLiveUsage> }) {
    const { t } = useTranslation('mailboxes');
    const { used, checkedAt, usage } = live;
    return (
        <section aria-label={t('edit.storage')} className="rounded-[10px] border border-line bg-subtle px-3.5 py-3">
            <div className={sectionTitle}>{t('edit.storageUsed')}</div>
            <StorageBar
                used={used}
                limit={m.quota_bytes}
                disabled={m.status === 'disabled'}
                caption={
                    used === null
                        ? t('edit.notChecked', { limit: formatBytes(m.quota_bytes) })
                        : checkedAt
                          ? t('edit.usedOfChecked', { used: formatBytes(used), limit: formatBytes(m.quota_bytes), when: formatRelative(checkedAt) })
                          : t('edit.usedOf', { used: formatBytes(used), limit: formatBytes(m.quota_bytes) })
                }
            />
            {usage.isError && (
                <p role="status" className="mt-1.5 text-[12.5px] text-warn">
                    {errorText(usage.error, t('edit.usageUnavailable'))}
                </p>
            )}
        </section>
    );
}

/**
 * Which of the optional delivery switches (backend plan section 10) this mailbox shows: only where the server
 * has the column (`capabilities.schema_extension`) and the mailbox reports a value for it.
 */
function useDeliverySwitches(m: Mailbox) {
    const me = useMe();
    const ext = me.data?.capabilities?.schema_extension;
    return {
        stopReceiving: ext?.receiving === true && typeof m.receiving === 'boolean',
        sendonly: ext?.sendonly === true && typeof m.sendonly === 'boolean',
    };
}

/** The forwarding addresses that deliver to this mailbox, each linking to the Forwarding tab. */
function ForwardingSources({ m, domain, unicode, onAdd }: { m: Mailbox; domain: string; unicode: string; onAdd?: () => void }) {
    const { t } = useTranslation('mailboxes');
    const headingId = useId();
    const local = m.local;
    const forwarding = useQuery({
        queryKey: queryKeys.forwarding(domain, { target: `${local}@${domain}`, limit: 200 }),
        queryFn: () =>
            apiFetch<ForwardingList>(`/domains/${encodeURIComponent(domain)}/forwarding`, {
                query: { target: `${local}@${domain}`, limit: 200 },
            }),
    });
    const forwardingTab = `/domains/${encodeURIComponent(domain)}/forwarding`;
    const sources = forwarding.data?.data ?? [];

    return (
        <section aria-labelledby={headingId}>
            <div className="mb-1.5 flex items-center">
                <span id={headingId} className="text-[13px] font-semibold text-ink">
                    {t('edit.forwardingHeading')}
                </span>
                {onAdd && (
                    <Button variant="link" size="sm" className="ml-auto" onClick={onAdd}>
                        {t('edit.forwardingAdd')}
                    </Button>
                )}
            </div>
            {forwarding.isPending ? (
                <p className="text-[12.5px] text-muted">{t('edit.loading')}</p>
            ) : forwarding.isError ? (
                <p role="alert" className="text-[12.5px] text-danger">
                    {errorText(forwarding.error, t('edit.forwardingLoadFailed'))}
                </p>
            ) : sources.length === 0 ? (
                <p className="text-[12.5px] text-muted">{t('edit.forwardingNone')}</p>
            ) : (
                <ul className="flex flex-wrap gap-1.5">
                    {sources.map((s) => (
                        <li key={s.source}>
                            <Link
                                to={`${forwardingTab}?q=${encodeURIComponent(s.catch_all ? s.source : s.local)}`}
                                className="inline-block rounded-md border border-line bg-subtle px-2 py-1 text-[12.5px] font-medium wrap-anywhere text-ink hover:bg-white"
                            >
                                {s.catch_all ? t('edit.catchAll', { domain: unicode }) : displayAddress(s.local, unicode)}
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

/** What a viewer sees: the storage, name, status and forwarding, with nothing to change. */
function ReadOnlyDetail({ m, domain, unicode, path }: { m: Mailbox; domain: string; unicode: string; path: string }) {
    const { t } = useTranslation('mailboxes');
    const live = useLiveUsage(m, domain, path);
    const switches = useDeliverySwitches(m);
    return (
        <div className="flex min-h-full flex-col gap-5 text-[13.5px]">
            <p className="rounded-lg border border-line bg-subtle px-3 py-2 text-[12.5px] text-body">{t('edit.readOnly')}</p>
            <StorageSection m={m} live={live} />
            <dl className="grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-2">
                <dt className="font-semibold text-ink">{t('edit.displayName')}</dt>
                <dd className={m.display_name ? 'wrap-anywhere text-body' : 'text-muted'}>{m.display_name || t('edit.noDisplayName')}</dd>
                <dt className="font-semibold text-ink">{t('list.columnStatus')}</dt>
                <dd className="text-body">{m.status === 'active' ? t('status.active') : t('status.disabled')}</dd>
                {switches.stopReceiving && (
                    <>
                        <dt className="font-semibold text-ink">{t('edit.stopReceiving')}</dt>
                        <dd className="text-body">{m.receiving === false ? t('edit.yes') : t('edit.no')}</dd>
                    </>
                )}
                {switches.sendonly && (
                    <>
                        <dt className="font-semibold text-ink">{t('edit.sendonly')}</dt>
                        <dd className="text-body">{m.sendonly === true ? t('edit.yes') : t('edit.no')}</dd>
                    </>
                )}
            </dl>
            <ForwardingSources m={m} domain={domain} unicode={unicode} />
        </div>
    );
}

interface EditorProps {
    m: Mailbox;
    domain: string;
    unicode: string;
    path: string;
    address: string;
    focus?: 'password';
    password: string | null;
    setPassword: (p: string | null) => void;
    passwordBusy: boolean;
    setPasswordBusy: (b: boolean) => void;
    onClose: () => void;
}

function Editor({ m, domain, unicode, path, address, focus, password, setPassword, passwordBusy, setPasswordBusy, onClose }: EditorProps) {
    const { t } = useTranslation('mailboxes');
    const queryClient = useQueryClient();
    const toast = useToast();
    // One guard per action, so a pending limit save does not swallow a click on the switch.
    const onceQuota = useSingleFlight();
    const onceName = useSingleFlight();
    const onceStatus = useSingleFlight();
    const onceDelivery = useSingleFlight();
    const oncePassword = useSingleFlight();
    const switches = useDeliverySwitches(m);
    const deliveryId = useId();
    const local = m.local;
    const passwordButtonId = useId();
    const copyId = useId();

    const live = useLiveUsage(m, domain, path);
    const { usage, used } = live;

    const [quota, setQuota] = useState<QuotaValue>({ bytes: m.quota_bytes, problem: null });
    const [quotaError, setQuotaError] = useState<string | undefined>();
    // null while untouched, so the field follows the server's value after a save.
    const [name, setName] = useState<string | null>(null);
    const [nameError, setNameError] = useState<string | undefined>();
    const [passwordError, setPasswordError] = useState<string | undefined>();
    const [copied, setCopied] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [addingForward, setAddingForward] = useState(false);
    const [welcoming, setWelcoming] = useState(false);

    const disabled = m.status === 'disabled';

    // Runs once per open: the deps never change while the drawer shows one mailbox.
    useEffect(() => {
        if (focus !== 'password') return;
        // After the drawer's own open focus, which runs later in the same commit when the mailbox was cached.
        const t = setTimeout(() => {
            const button = document.getElementById(passwordButtonId);
            // jsdom has no scrollIntoView.
            if (typeof button?.scrollIntoView === 'function') button.scrollIntoView({ block: 'center' });
            button?.focus();
        }, 0);
        return () => clearTimeout(t);
    }, [focus, passwordButtonId]);

    useEffect(() => {
        if (password !== null) document.getElementById(copyId)?.focus();
    }, [password, copyId]);

    async function afterWrite() {
        await refreshAfterMailboxWrite(queryClient);
    }

    const saveQuota = useMutation({
        mutationFn: (bytes: number) => apiFetch<Mailbox>(path, { method: 'PATCH', json: { quota_bytes: bytes } }),
        onSuccess: async () => {
            toast.show({ kind: 'success', text: t('edit.limitSaved') });
            await afterWrite();
        },
        onError: (e) => setQuotaError(fieldErrors(e).quota_bytes ?? errorText(e, t('edit.limitFailed'))),
    });

    const saveName = useMutation({
        mutationFn: (displayName: string | null) => apiFetch<Mailbox>(path, { method: 'PATCH', json: { display_name: displayName } }),
        onSuccess: async () => {
            toast.show({ kind: 'success', text: t('edit.nameSaved') });
            await afterWrite();
            setName(null);
        },
        onError: (e) => setNameError(fieldErrors(e).display_name ?? errorText(e, t('edit.nameFailed'))),
    });

    const setStatus = useMutation({
        mutationFn: (enable: boolean) => apiFetch<undefined>(`${path}/${enable ? 'enable' : 'disable'}`, { method: 'POST' }),
        onSuccess: async (_, enable) => {
            await afterWrite();
            toast.show({ kind: 'success', text: t(enable ? 'toggle.enabled' : 'toggle.disabled', { address }) });
        },
        onError: (e) => toast.show({ kind: 'danger', text: errorText(e, t('toggle.failed')) }),
    });

    // The optional switches: one field per request, so a refusal names the field it is about.
    const setDelivery = useMutation({
        mutationFn: (body: { receiving: boolean } | { sendonly: boolean }) => apiFetch<Mailbox>(path, { method: 'PATCH', json: body }),
        onSuccess: async (_, body) => {
            const text =
                'receiving' in body
                    ? t(body.receiving ? 'edit.receivingOn' : 'edit.receivingOff', { address })
                    : t(body.sendonly ? 'edit.sendonlyOn' : 'edit.sendonlyOff', { address });
            toast.show({ kind: 'success', text });
            await afterWrite();
        },
        onError: (e, body) => {
            const field = 'receiving' in body ? 'receiving' : 'sendonly';
            toast.show({ kind: 'danger', text: fieldErrors(e)[field] ?? errorText(e, t('edit.switchFailed')) });
            // The columns went away: `me` says so again after a fresh read, and the switches hide.
            if (e instanceof ApiProblem && e.code === 'schema_extension_missing') void queryClient.invalidateQueries({ queryKey: queryKeys.me });
        },
    });

    const quotaBytes = quota.problem === null ? quota.bytes : null;
    // The server does not refuse a limit below the space used, so wait for the live figure to check it.
    const canSaveQuota = !usage.isPending && quotaBytes !== null && quotaBytes !== m.quota_bytes;
    const nameValue = name ?? m.display_name ?? '';
    const trimmedName = nameValue.trim();
    const canSaveName = trimmedName !== (m.display_name ?? '');

    async function generate() {
        setPasswordBusy(true);
        setPasswordError(undefined);
        try {
            const result = await apiFetch<NewPassword>(`${path}/password`, { method: 'POST', json: { generate: true } });
            setCopied(false);
            setPassword(result.password);
            void afterWrite();
        } catch (e) {
            setPasswordError(errorText(e, t('edit.generateFailed')));
        } finally {
            setPasswordBusy(false);
        }
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

    return (
        <div className="flex min-h-full flex-col gap-5 text-[13.5px]">
            <StorageSection m={m} live={live} />

            <form
                noValidate
                onSubmit={(e) => {
                    e.preventDefault();
                    if (!canSaveQuota) return;
                    setQuotaError(undefined);
                    onceQuota(() => saveQuota.mutateAsync(quotaBytes));
                }}
            >
                <QuotaInput label={t('edit.storageLimit')} defaultBytes={m.quota_bytes} usedBytes={used} onChange={setQuota} />
                {quotaError && (
                    <p role="alert" className="mt-1 text-[12.5px] text-danger">
                        {quotaError}
                    </p>
                )}
                <Button type="submit" variant="primary" className="mt-2.5" disabled={!canSaveQuota} busy={saveQuota.isPending}>
                    {t('edit.saveLimit')}
                </Button>
            </form>

            <form
                noValidate
                onSubmit={(e) => {
                    e.preventDefault();
                    if (!canSaveName) return;
                    setNameError(undefined);
                    onceName(() => saveName.mutateAsync(trimmedName === '' ? null : trimmedName));
                }}
            >
                <TextInput
                    label={t('edit.displayName')}
                    value={nameValue}
                    maxLength={100}
                    placeholder={t('edit.noDisplayName')}
                    onChange={(e) => {
                        setName(e.target.value);
                        setNameError(undefined);
                    }}
                    error={nameError}
                />
                <Button type="submit" className="mt-2.5" disabled={!canSaveName} busy={saveName.isPending}>
                    {t('edit.saveName')}
                </Button>
            </form>

            <section aria-labelledby={`${passwordButtonId}-title`}>
                <div id={`${passwordButtonId}-title`} className={sectionTitle}>
                    {t('edit.passwordHeading')}
                </div>
                {password === null ? (
                    <>
                        <Button id={passwordButtonId} busy={passwordBusy} onClick={() => oncePassword(generate)}>
                            {t('edit.generate')}
                        </Button>
                        <p className="mt-1.5 text-[12.5px] text-muted">{t('edit.generateHint')}</p>
                        {passwordError && (
                            <p role="alert" className="mt-1 text-[12.5px] text-danger">
                                {passwordError}
                            </p>
                        )}
                    </>
                ) : (
                    <div className="rounded-[10px] border border-warn-line bg-warn-bg p-4">
                        <div className="text-xs font-bold tracking-[.06em] text-warn uppercase">{t('password.newShownOnce')}</div>
                        <div className="mt-2.5 flex gap-2 max-md:flex-col">
                            <div className="flex h-11 min-w-0 flex-1 items-center overflow-x-auto rounded-lg border border-line bg-white px-3 font-mono text-[15px] whitespace-nowrap select-all max-md:h-12 max-md:text-base">
                                {password}
                            </div>
                            <Button id={copyId} variant="dark" size="lg" className="h-11 min-w-[84px]" onClick={() => copy(password)}>
                                {copied ? t('password.copied') : t('password.copy')}
                            </Button>
                        </div>
                        <p className="mt-2.5 text-[12.5px] leading-normal text-body">
                            {t('password.giveToOwner')}
                        </p>
                        <Button size="sm" className="mt-2.5" onClick={() => setPassword(null)}>
                            {t('password.done')}
                        </Button>
                    </div>
                )}
            </section>

            <section aria-label={t('welcome.heading')}>
                <div className={sectionTitle}>{t('welcome.heading')}</div>
                <Button onClick={() => setWelcoming(true)}>{t('welcome.button')}</Button>
                <p className="mt-1.5 text-[12.5px] text-muted">{t('welcome.hint')}</p>
            </section>

            <ForwardingSources m={m} domain={domain} unicode={unicode} onAdd={() => setAddingForward(true)} />

            <Switch
                checked={!disabled}
                label={disabled ? t('edit.switchDisabled') : t('edit.switchEnabled')}
                disabled={setStatus.isPending}
                onChange={(enable) => onceStatus(() => setStatus.mutateAsync(enable))}
            />

            {(switches.stopReceiving || switches.sendonly) && (
                <section aria-labelledby={deliveryId} className="flex flex-col gap-3">
                    <div id={deliveryId} className={sectionTitle}>
                        {t('edit.deliveryHeading')}
                    </div>
                    {switches.stopReceiving && (
                        <div>
                            <Switch
                                checked={m.receiving === false}
                                label={t('edit.stopReceiving')}
                                disabled={setDelivery.isPending}
                                onChange={(stop) => onceDelivery(() => setDelivery.mutateAsync({ receiving: !stop }))}
                            />
                            <p className="mt-1.5 text-[12.5px] leading-normal text-muted">{t('edit.stopReceivingHint')}</p>
                        </div>
                    )}
                    {switches.sendonly && (
                        <div>
                            <Switch
                                checked={m.sendonly === true}
                                label={t('edit.sendonly')}
                                disabled={setDelivery.isPending}
                                onChange={(on) => onceDelivery(() => setDelivery.mutateAsync({ sendonly: on }))}
                            />
                            <p className="mt-1.5 text-[12.5px] leading-normal text-muted">{t('edit.sendonlyHint')}</p>
                        </div>
                    )}
                </section>
            )}

            <section aria-label={t('edit.dangerZone')} className="mt-auto rounded-[10px] border border-danger-line p-3.5">
                <div className="text-[13px] font-bold text-danger">{t('edit.dangerZone')}</div>
                <p className="mt-1 text-[12.5px] leading-normal text-body">
                    {t('edit.dangerText')}
                </p>
                <Button variant="danger" className="mt-2.5" onClick={() => setDeleting(true)}>
                    {t('edit.deleteMailbox')}
                </Button>
            </section>

            <ForwardingDrawer
                open={addingForward}
                onClose={() => setAddingForward(false)}
                domain={domain}
                initialTargets={[`${local}@${domain}`]}
            />
            <SendWelcomeDialog open={welcoming} onClose={() => setWelcoming(false)} domain={domain} local={local} address={address} />
            <DeleteMailboxDialog
                open={deleting}
                onClose={() => setDeleting(false)}
                domain={domain}
                local={local}
                address={address}
                onDeleted={onClose}
            />
        </div>
    );
}
