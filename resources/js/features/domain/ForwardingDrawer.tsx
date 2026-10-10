import { useId, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { apiFetch } from '../../api/client';
import { fieldErrors } from '../../api/problems';
import { queryKeys, type Json } from '../../api/types';
import { AddonInput } from '../../components/ui/AddonInput';
import { Button } from '../../components/ui/Button';
import type { ChipKind } from '../../components/ui/Chip';
import { ChipInput } from '../../components/ui/ChipInput';
import { Drawer } from '../../components/ui/Drawer';
import { Select } from '../../components/ui/Select';
import { Switch } from '../../components/ui/Switch';
import { useToast } from '../../components/ui/Toast';
import { useDomainNames } from '../../layout/useNavData';
import {
    displayAddress,
    errorText,
    forwardingPath,
    mailboxesPath,
    refreshAfterForwardingWrite,
    useDebounced,
    useSingleFlight,
    type ForwardingList,
    type ForwardingRow,
    type MailboxList,
} from './shared';

export type { ForwardingRow } from './shared';

type AddressCheck = Json<'/domains/{domain}/addresses/check', 'get'>;

export interface ForwardingDrawerProps {
    open: boolean;
    onClose: () => void;
    /** Punycode domain name. Without it, as from the dashboard, the drawer asks for the domain first. */
    domain?: string;
    /** The group to edit; its source is then read-only. */
    editing?: ForwardingRow;
    /** Destinations to start a new group with, such as the mailbox it was opened from. */
    initialTargets?: string[];
}

interface PutBody {
    targets: string[];
    keep_copy?: boolean;
    redirect_mailbox?: boolean;
}

const CHECK_DELAY = 300;
const MAX_TARGETS = 50;
const MAX_ADDRESS = 100;
const SUGGESTED = ['info', 'hr'] as const;

const noteClass = 'mt-1 text-[12.5px]';
const alertBox = 'rounded-lg border border-danger-line bg-danger-bg px-3 py-2.5 text-[13px] text-danger';

export function ForwardingDrawer({ open, ...rest }: ForwardingDrawerProps) {
    // Mounting the body only while open starts every opening fresh.
    return open ? <Body key={rest.editing?.source ?? 'new'} {...rest} /> : null;
}

function domainOf(address: string): string {
    return address.slice(address.lastIndexOf('@') + 1);
}

/** One row of the forwarding list by exact source local part, when the lookup found it. */
function useExistingGroup(domain: string, local: string, enabled: boolean) {
    return useQuery({
        queryKey: queryKeys.forwarding(domain, { q: local, limit: 200 }),
        queryFn: () => apiFetch<ForwardingList>(forwardingPath(domain), { query: { q: local, limit: 200 } }),
        enabled,
        select: (r) => r.data.find((row) => !row.catch_all && row.local === local) ?? null,
    });
}

/** Whether `domain` has a mailbox with exactly the local part `local`. */
function useHasMailbox(domain: string, local: string) {
    return useQuery({
        queryKey: queryKeys.mailboxes(domain, { q: local, limit: 200 }),
        queryFn: () => apiFetch<MailboxList>(mailboxesPath(domain), { query: { q: local, limit: 200 } }),
        enabled: domain !== '',
        select: (r) => r.data.some((m) => m.local === local),
    });
}

function Body({ onClose, domain, editing, initialTargets }: Omit<ForwardingDrawerProps, 'open'>) {
    const queryClient = useQueryClient();
    const toast = useToast();
    const once = useSingleFlight();
    const names = useDomainNames();
    const formId = useId();
    const sourceId = useId();

    const [picked, setPicked] = useState('');
    const [row, setRow] = useState<ForwardingRow | null>(editing ?? null);
    const [local, setLocal] = useState(editing?.local ?? '');
    const [targets, setTargets] = useState<string[]>(() => {
        const start = editing ? editing.targets.map((t) => t.address) : [];
        for (const t of initialTargets ?? []) if (!start.includes(t)) start.push(t);
        return start;
    });
    // Kinds the client knows for sure: the server's for stored targets, `mailbox` for a suggestion.
    const [kinds, setKinds] = useState<Record<string, ChipKind>>(() =>
        Object.fromEntries((editing?.targets ?? []).map((t) => [t.address, t.kind])),
    );
    const [draft, setDraft] = useState('');
    const [keepCopy, setKeepCopy] = useState(editing ? editing.keep_copy : true);
    // A group that is already forward-only needs no new confirmation to stay that way.
    const [offConfirmed, setOffConfirmed] = useState(editing ? !editing.keep_copy : false);
    const [errors, setErrors] = useState<{ local?: string; targets?: string; redirect?: string }>({});
    const [formError, setFormError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const domainName = domain ?? (picked || names.data?.[0]?.name || '');
    const unicode = names.data?.find((d) => d.name === domainName)?.unicode_name ?? domainName;

    // The live check of a new source.
    const typed = local.trim().toLowerCase();
    const debounced = useDebounced(typed, CHECK_DELAY);
    const check = useQuery({
        queryKey: queryKeys.addressCheck(domainName, debounced),
        queryFn: () => apiFetch<AddressCheck>(`/domains/${encodeURIComponent(domainName)}/addresses/check`, { query: { local: debounced } }),
        enabled: row === null && domainName !== '' && debounced !== '',
    });
    const checkSettled = row === null && typed !== '' && debounced === typed && !check.isPending;
    const result = checkSettled ? check.data : undefined;
    // The check reports `mailbox` before `forwarding`, so a mailbox that already forwards needs a lookup:
    // a PUT would otherwise silently replace its destinations.
    const lookup = useExistingGroup(domainName, debounced, result?.status === 'mailbox' || result?.status === 'forwarding');
    const lookupSettled = result !== undefined && (result.status !== 'mailbox' || !lookup.isPending);
    const existing = lookupSettled ? (lookup.data ?? null) : null;

    const checking = row === null && typed !== '' && (!checkSettled || !lookupSettled);
    const status = result?.status;
    const lookupFailed = status === 'mailbox' && lookupSettled && lookup.isError;
    const exists = status === 'forwarding' || (status === 'mailbox' && existing !== null);
    const newOk = !checking && !lookupFailed && !exists && (status === 'available' || status === 'mailbox');
    const sourceOk = row !== null || newOk;
    const sourceIsMailbox = row !== null ? row.source_is_mailbox : newOk && status === 'mailbox';

    const sourceLocal = row?.local ?? typed;
    const sourceAddress = sourceLocal === '' ? null : `${sourceLocal}@${domainName}`;
    const shownSource = displayAddress(sourceLocal, unicode);

    const info = useHasMailbox(domainName, 'info');
    const hr = useHasMailbox(domainName, 'hr');
    const suggestions = SUGGESTED.filter((l, i) => {
        const has = (i === 0 ? info : hr).data === true;
        const address = `${l}@${domainName}`;
        return has && !targets.includes(address) && address !== sourceAddress;
    });

    function kindOf(a: string): ChipKind {
        const known = kinds[a];
        if (known) return known;
        if (!names.data) return 'server';
        return names.data.some((d) => d.name === domainOf(a)) ? 'server' : 'external';
    }

    function validate(a: string): string | null {
        if (a.length > MAX_ADDRESS) return `Addresses are at most ${MAX_ADDRESS} characters.`;
        if (!/^[^\s@,]+@[^\s@,]+\.[^\s@,]+$/.test(a)) return `“${a}” is not a complete address.`;
        if (a === sourceAddress) return 'An address can’t deliver to itself.';
        if (targets.length >= MAX_TARGETS) return `A source can forward to at most ${MAX_TARGETS} destinations.`;
        return null;
    }

    const pending = draft.trim().toLowerCase();
    const pendingOk = pending !== '' && validate(pending) === null;
    const count = targets.length + (pendingOk ? 1 : 0);
    const awaitingConfirm = sourceIsMailbox && !keepCopy && !offConfirmed;
    const canSubmit = domainName !== '' && sourceOk && count >= 1 && count <= MAX_TARGETS && !awaitingConfirm;

    function changeTargets(next: string[]) {
        setTargets(next);
        setErrors((e) => (e.targets === undefined ? e : { ...e, targets: undefined }));
    }

    function editInstead(group: ForwardingRow) {
        setRow(group);
        setLocal(group.local);
        setTargets((current) => [...group.targets.map((t) => t.address), ...current.filter((t) => !group.targets.some((g) => g.address === t))]);
        setKinds((k) => ({ ...k, ...Object.fromEntries(group.targets.map((t) => [t.address, t.kind])) }));
        setKeepCopy(group.keep_copy);
        setOffConfirmed(!group.keep_copy);
        setErrors({});
        setFormError(null);
    }

    function setKeep(on: boolean) {
        setKeepCopy(on);
        setOffConfirmed(false);
        setErrors((e) => (e.redirect === undefined ? e : { ...e, redirect: undefined }));
    }

    async function save(list: string[]) {
        setBusy(true);
        setErrors({});
        setFormError(null);
        const json: PutBody = { targets: list };
        if (sourceIsMailbox) {
            json.keep_copy = keepCopy;
            if (!keepCopy) json.redirect_mailbox = true;
        }
        try {
            await apiFetch<ForwardingRow>(forwardingPath(domainName, sourceLocal), { method: 'PUT', json });
            toast.show({ kind: 'success', text: `${shownSource} now delivers to ${list.length} ${list.length === 1 ? 'place' : 'places'}.` });
            void refreshAfterForwardingWrite(queryClient);
            setBusy(false);
            onClose();
        } catch (e) {
            const fields = fieldErrors(e);
            const targetError = fields.targets ?? Object.entries(fields).find(([k]) => k.startsWith('targets.'))?.[1];
            const mine = { local: fields.local, targets: targetError, redirect: fields.redirect_mailbox };
            setErrors(mine);
            if (!mine.local && !mine.targets && !mine.redirect) setFormError(errorText(e, 'The forwarding address could not be saved.'));
            setBusy(false);
        }
    }

    function submit() {
        if (!canSubmit) return;
        if (pending !== '' && !pendingOk) {
            setErrors((e) => ({ ...e, targets: validate(pending) ?? undefined }));
            return;
        }
        const list = pendingOk && !targets.includes(pending) ? [...targets, pending] : targets;
        if (list !== targets) setTargets(list);
        once(() => save(list));
    }

    // Every close path (Cancel, ×, Escape, backdrop) waits while the save is pending.
    const close = busy ? () => {} : onClose;

    const checkFailed = row === null && typed !== '' && checkSettled && check.isError;
    const refused = status === 'blocked' || status === 'archiving' || status === 'invalid';
    const existsMessage = status === 'forwarding' ? result?.message : `${result?.address ?? ''} already forwards mail.`;
    const sourceError =
        errors.local ??
        (refused ? result?.message : undefined) ??
        (exists ? existsMessage : undefined) ??
        (lookupFailed ? errorText(lookup.error, 'The address could not be checked.') : undefined) ??
        (checkFailed ? errorText(check.error, 'The address could not be checked.') : undefined);
    const sourceHint =
        row !== null
            ? 'Editing destinations of an existing address.'
            : checking
              ? 'Checking…'
              : typed === ''
                ? 'Can be a new address or an existing mailbox (its mail is then also forwarded).'
                : undefined;
    const okMessage = !sourceError && newOk ? result?.message : undefined;

    return (
        <Drawer
            open
            onClose={close}
            closeDisabled={busy}
            title={row ? 'Edit forwarding' : 'New forwarding address'}
            subtitle={unicode === '' ? undefined : `Domains / ${unicode} / Forwarding`}
            footer={
                <>
                    <Button size="lg" disabled={busy} onClick={close}>
                        Cancel
                    </Button>
                    <div className="flex-1" />
                    <Button type="submit" form={formId} variant="primary" size="lg" className="min-w-[150px] max-md:flex-1" disabled={!canSubmit} busy={busy}>
                        {row ? 'Save destinations' : 'Add forwarding address'}
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
                    <Select label="Domain" value={domainName} disabled={!names.data || row !== null} onChange={(e) => setPicked(e.target.value)}>
                        {(names.data ?? []).map((d) => (
                            <option key={d.name} value={d.name}>
                                {d.unicode_name}
                            </option>
                        ))}
                    </Select>
                )}

                <div>
                    <AddonInput
                        id={sourceId}
                        label="Address that receives the mail"
                        domain={unicode}
                        placeholder="name"
                        autoComplete="off"
                        value={local}
                        readOnly={row !== null}
                        onChange={(e) => {
                            setLocal(e.target.value);
                            // A new source starts with the copy kept and nothing confirmed.
                            setKeepCopy(true);
                            setOffConfirmed(false);
                            setErrors((x) => ({ ...x, local: undefined, redirect: undefined }));
                        }}
                        error={sourceError}
                        hint={sourceHint}
                        aria-describedby={sourceError || sourceHint ? `${sourceId}-msg` : okMessage ? `${sourceId}-ok` : undefined}
                    />
                    {okMessage && (
                        <p id={`${sourceId}-ok`} role="status" className={`${noteClass} ${status === 'mailbox' ? 'text-warn' : 'text-teal-dark'}`}>
                            {status === 'mailbox' ? `${okMessage} Its mail is forwarded as well.` : okMessage}
                        </p>
                    )}
                    {exists && !errors.local && existing && (
                        <Button variant="link" size="sm" className="mt-1" onClick={() => editInstead(existing)}>
                            Edit it instead
                        </Button>
                    )}
                </div>

                <ChipInput
                    label="Deliver to"
                    value={targets}
                    onChange={changeTargets}
                    validate={validate}
                    kindOf={kindOf}
                    onDraftChange={setDraft}
                    placeholder={targets.length > 0 ? 'Add another…' : `name@${unicode || 'example.org'} or any address`}
                    error={errors.targets}
                    hint="Press Enter after each address. Addresses outside this server are forwarded externally."
                />

                {targets.some((a) => kindOf(a) === 'external') && (
                    <p className="flex gap-2 rounded-lg border border-warn-line bg-warn-bg px-3 py-2.5 text-[13px] text-warn">
                        <span aria-hidden="true" className="font-extrabold">
                            !
                        </span>
                        <span>Mail forwarded to a wrong external address is lost. Double-check it.</span>
                    </p>
                )}

                {suggestions.length > 0 && (
                    <p className="text-[12.5px] text-muted">
                        <b className="font-semibold text-body">Suggestions</b> —{' '}
                        {suggestions.map((l, i) => (
                            <span key={l}>
                                {i > 0 && ' · '}
                                <button
                                    type="button"
                                    className="font-semibold text-teal-dark hover:underline focus-visible:outline-2 focus-visible:outline-teal"
                                    onClick={() => {
                                        const address = `${l}@${domainName}`;
                                        setKinds((k) => ({ ...k, [address]: 'mailbox' }));
                                        changeTargets([...targets, address]);
                                    }}
                                >
                                    {`${l}@${unicode}`}
                                </button>
                            </span>
                        ))}
                    </p>
                )}

                {sourceIsMailbox && (
                    <div className="flex flex-col gap-2">
                        <Switch checked={keepCopy} label="Keep a copy in the mailbox" disabled={busy} onChange={setKeep} />
                        {awaitingConfirm ? (
                            <div className="rounded-lg border border-warn-line bg-warn-bg px-3 py-2.5 text-[13px] text-body">
                                <p>Mail to {shownSource} will only be forwarded, not kept.</p>
                                <div className="mt-2 flex gap-2">
                                    <Button size="sm" variant="primary" onClick={() => setOffConfirmed(true)}>
                                        Confirm
                                    </Button>
                                    <Button size="sm" aria-label="Cancel turning off" onClick={() => setKeep(true)}>
                                        Cancel
                                    </Button>
                                </div>
                            </div>
                        ) : (
                            <p className="text-[12.5px] text-muted">
                                {keepCopy ? 'The mailbox keeps its mail and a copy is forwarded.' : 'Mail is forwarded only; the mailbox keeps nothing new.'}
                            </p>
                        )}
                    </div>
                )}

                {errors.redirect && (
                    <p role="alert" className={alertBox}>
                        {errors.redirect}
                    </p>
                )}
                {formError && (
                    <p role="alert" className={alertBox}>
                        {formError}
                    </p>
                )}
            </form>
        </Drawer>
    );
}
