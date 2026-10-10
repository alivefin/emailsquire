import { useEffect, useId, useState } from 'react';
import { Link } from 'react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiFetch } from '../../api/client';
import { fieldErrors } from '../../api/problems';
import { queryKeys, type Json } from '../../api/types';
import { Button } from '../../components/ui/Button';
import { Drawer } from '../../components/ui/Drawer';
import { QuotaInput, type QuotaValue } from '../../components/ui/QuotaInput';
import { StorageBar } from '../../components/ui/StorageBar';
import { Switch } from '../../components/ui/Switch';
import { TextInput } from '../../components/ui/TextInput';
import { useToast } from '../../components/ui/Toast';
import { formatBytes, formatRelative } from '../../lib/format';
import { useDomainNames } from '../../layout/useNavData';
import { DeleteMailboxDialog } from './DeleteMailboxDialog';
import { displayAddress, errorText, mailboxesPath, refreshAfterMailboxWrite, useSingleFlight, type Mailbox } from './shared';

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

const ENABLED = 'Enabled — sign-ins allowed.';
const DISABLED = 'Disabled — keeps receiving mail; sign-ins stop.';

const sectionTitle = 'mb-1.5 text-[13px] font-semibold text-ink';

export function EditMailboxDrawer({ open, ...rest }: EditMailboxDrawerProps) {
    // Mounting the body only while open, keyed by the mailbox, drops the shown-once password on close
    // and when another mailbox opens.
    return open ? <Body key={`${rest.domain}/${rest.local}`} {...rest} /> : null;
}

function Body({ onClose, domain, local, focus }: Omit<EditMailboxDrawerProps, 'open'>) {
    const names = useDomainNames();
    const unicode = names.data?.find((d) => d.name === domain)?.unicode_name ?? domain;
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

    const m = mailbox.data;
    const subtitle = `${m?.display_name || 'No display name'} · Domains / ${unicode} / Mailboxes`;

    return (
        <Drawer open onClose={close} closeDisabled={passwordBusy} title={address} subtitle={m ? subtitle : undefined}>
            {m ? (
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
                    {errorText(mailbox.error, 'The mailbox could not be loaded.')}
                </p>
            ) : (
                <p aria-busy="true" className="text-[13px] text-muted">
                    Loading…
                </p>
            )}
        </Drawer>
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
    const queryClient = useQueryClient();
    const toast = useToast();
    // One guard per action, so a pending limit save does not swallow a click on the switch.
    const onceQuota = useSingleFlight();
    const onceName = useSingleFlight();
    const onceStatus = useSingleFlight();
    const oncePassword = useSingleFlight();
    const local = m.local;
    const passwordButtonId = useId();
    const copyId = useId();

    const usage = useQuery({
        queryKey: queryKeys.usage(domain, local),
        queryFn: () => apiFetch<Usage>(`${path}/usage`),
        // A 503 means doveadm could not answer; the list's figure stands in at once instead of after retries.
        retry: false,
    });
    const forwarding = useQuery({
        queryKey: queryKeys.forwarding(domain, { target: `${local}@${domain}`, limit: 200 }),
        queryFn: () =>
            apiFetch<ForwardingList>(`/domains/${encodeURIComponent(domain)}/forwarding`, {
                query: { target: `${local}@${domain}`, limit: 200 },
            }),
    });

    const [quota, setQuota] = useState<QuotaValue>({ bytes: m.quota_bytes, problem: null });
    const [quotaError, setQuotaError] = useState<string | undefined>();
    // null while untouched, so the field follows the server's value after a save.
    const [name, setName] = useState<string | null>(null);
    const [nameError, setNameError] = useState<string | undefined>();
    const [passwordError, setPasswordError] = useState<string | undefined>();
    const [copied, setCopied] = useState(false);
    const [deleting, setDeleting] = useState(false);

    // On a usage failure the list's figure stands in for the live one.
    const live = usage.data;
    const used = live ? live.used_bytes : m.used_bytes;
    const checkedAt = live ? live.checked_at : m.usage_checked_at;
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
            toast.show({ kind: 'success', text: 'Limit saved.' });
            await afterWrite();
        },
        onError: (e) => setQuotaError(fieldErrors(e).quota_bytes ?? errorText(e, 'The limit could not be saved.')),
    });

    const saveName = useMutation({
        mutationFn: (displayName: string | null) => apiFetch<Mailbox>(path, { method: 'PATCH', json: { display_name: displayName } }),
        onSuccess: async () => {
            toast.show({ kind: 'success', text: 'Display name saved.' });
            await afterWrite();
            setName(null);
        },
        onError: (e) => setNameError(fieldErrors(e).display_name ?? errorText(e, 'The display name could not be saved.')),
    });

    const setStatus = useMutation({
        mutationFn: (enable: boolean) => apiFetch<undefined>(`${path}/${enable ? 'enable' : 'disable'}`, { method: 'POST' }),
        onSuccess: async (_, enable) => {
            await afterWrite();
            toast.show({ kind: 'success', text: `${enable ? 'Enabled' : 'Disabled'} ${address}.` });
        },
        onError: (e) => toast.show({ kind: 'danger', text: errorText(e, 'The mailbox could not be changed.') }),
    });

    const quotaBytes = quota.problem === null ? quota.bytes : null;
    const canSaveQuota = quotaBytes !== null && quotaBytes !== m.quota_bytes;
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
            setPasswordError(errorText(e, 'A new password could not be generated.'));
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
                () => toast.show({ kind: 'danger', text: 'The password could not be copied. Select it and copy it by hand.' }),
            );
    }

    const forwardingTab = `/domains/${encodeURIComponent(domain)}/forwarding`;
    const sources = forwarding.data?.data ?? [];

    return (
        <div className="flex min-h-full flex-col gap-5 text-[13.5px]">
            <section aria-label="Storage" className="rounded-[10px] border border-line bg-subtle px-3.5 py-3">
                <div className={sectionTitle}>Storage used</div>
                <StorageBar
                    used={used}
                    limit={m.quota_bytes}
                    disabled={disabled}
                    caption={
                        used === null
                            ? `Not checked yet · limit ${formatBytes(m.quota_bytes)}`
                            : `${formatBytes(used)} of ${formatBytes(m.quota_bytes)} used${checkedAt ? ` · checked ${formatRelative(checkedAt)}` : ''}`
                    }
                />
                {usage.isError && (
                    <p role="status" className="mt-1.5 text-[12.5px] text-warn">
                        {errorText(usage.error, 'Storage use is unavailable right now.')}
                    </p>
                )}
            </section>

            <form
                noValidate
                onSubmit={(e) => {
                    e.preventDefault();
                    if (!canSaveQuota) return;
                    setQuotaError(undefined);
                    onceQuota(() => saveQuota.mutateAsync(quotaBytes));
                }}
            >
                <QuotaInput label="Storage limit" defaultBytes={m.quota_bytes} usedBytes={used} onChange={setQuota} />
                {quotaError && (
                    <p role="alert" className="mt-1 text-[12.5px] text-danger">
                        {quotaError}
                    </p>
                )}
                <Button type="submit" variant="primary" className="mt-2.5" disabled={!canSaveQuota} busy={saveQuota.isPending}>
                    Save limit
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
                    label="Display name"
                    value={nameValue}
                    maxLength={100}
                    placeholder="No display name"
                    onChange={(e) => {
                        setName(e.target.value);
                        setNameError(undefined);
                    }}
                    error={nameError}
                />
                <Button type="submit" className="mt-2.5" disabled={!canSaveName} busy={saveName.isPending}>
                    Save name
                </Button>
            </form>

            <section aria-labelledby={`${passwordButtonId}-title`}>
                <div id={`${passwordButtonId}-title`} className={sectionTitle}>
                    Password
                </div>
                {password === null ? (
                    <>
                        <Button id={passwordButtonId} busy={passwordBusy} onClick={() => oncePassword(generate)}>
                            Generate a new password
                        </Button>
                        <p className="mt-1.5 text-[12.5px] text-muted">The current one can’t be viewed. The new one is shown once.</p>
                        {passwordError && (
                            <p role="alert" className="mt-1 text-[12.5px] text-danger">
                                {passwordError}
                            </p>
                        )}
                    </>
                ) : (
                    <div className="rounded-[10px] border border-warn-line bg-warn-bg p-4">
                        <div className="text-xs font-bold tracking-[.06em] text-warn uppercase">New password — shown once</div>
                        <div className="mt-2.5 flex gap-2 max-md:flex-col">
                            <div className="flex h-11 min-w-0 flex-1 items-center overflow-x-auto rounded-lg border border-line bg-white px-3 font-mono text-[15px] whitespace-nowrap select-all max-md:h-12 max-md:text-base">
                                {password}
                            </div>
                            <Button id={copyId} variant="dark" size="lg" className="h-11 min-w-[84px]" onClick={() => copy(password)}>
                                {copied ? 'Copied' : 'Copy'}
                            </Button>
                        </div>
                        <p className="mt-2.5 text-[12.5px] leading-normal text-body">
                            Give this to the owner now. After you close this panel it can’t be shown again, only reset.
                        </p>
                        <Button size="sm" className="mt-2.5" onClick={() => setPassword(null)}>
                            Done
                        </Button>
                    </div>
                )}
            </section>

            <section aria-labelledby={`${passwordButtonId}-fwd`}>
                <div className="mb-1.5 flex items-center">
                    <span id={`${passwordButtonId}-fwd`} className="text-[13px] font-semibold text-ink">
                        Forwarding to this mailbox
                    </span>
                    {/* Task 13 replaces this link with the forwarding drawer, this address as the first destination. */}
                    <Link
                        to={`${forwardingTab}?q=${encodeURIComponent(local)}`}
                        className="ml-auto text-[13px] font-semibold text-teal-dark hover:underline"
                    >
                        + Add
                    </Link>
                </div>
                {forwarding.isPending ? (
                    <p className="text-[12.5px] text-muted">Loading…</p>
                ) : forwarding.isError ? (
                    <p role="alert" className="text-[12.5px] text-danger">
                        {errorText(forwarding.error, 'The forwarding list could not be loaded.')}
                    </p>
                ) : sources.length === 0 ? (
                    <p className="text-[12.5px] text-muted">No forwarding addresses point here yet.</p>
                ) : (
                    <ul className="flex flex-wrap gap-1.5">
                        {sources.map((s) => (
                            <li key={s.source}>
                                <Link
                                    to={`${forwardingTab}?q=${encodeURIComponent(s.catch_all ? s.source : s.local)}`}
                                    className="inline-block rounded-md border border-line bg-subtle px-2 py-1 text-[12.5px] font-medium wrap-anywhere text-ink hover:bg-white"
                                >
                                    {s.catch_all ? `Catch-all @${unicode}` : displayAddress(s.local, unicode)}
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            <Switch
                checked={!disabled}
                label={disabled ? DISABLED : ENABLED}
                disabled={setStatus.isPending}
                onChange={(enable) => onceStatus(() => setStatus.mutateAsync(enable))}
            />

            <section aria-label="Danger zone" className="mt-auto rounded-[10px] border border-danger-line p-3.5">
                <div className="text-[13px] font-bold text-danger">Danger zone</div>
                <p className="mt-1 text-[12.5px] leading-normal text-body">
                    Sign-ins stop and its mail is moved to trash, where it can be restored for 30 days.
                </p>
                <Button variant="danger" className="mt-2.5" onClick={() => setDeleting(true)}>
                    Delete mailbox…
                </Button>
            </section>

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
