import { useEffect, useId, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
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
import { useDomainNames } from '../../layout/useNavData';
import { displayAddress, errorText, mailboxesPath, refreshAfterMailboxWrite, useDebounced, useSingleFlight, type Mailbox } from './shared';

type AddressCheck = Json<'/domains/{domain}/addresses/check', 'get'>;

export interface NewMailboxDrawerProps {
    open: boolean;
    onClose: () => void;
    /** Punycode domain name. Without it, as from the dashboard, the drawer asks for the domain first. */
    domain?: string;
}

const CHECK_DELAY = 300;
const FIELDS = ['local', 'display_name', 'password', 'quota_bytes'] as const;
type FieldName = (typeof FIELDS)[number];

/** What the "shown once" panel needs. The password lives here and nowhere else. */
interface Created {
    address: string;
    password: string;
    quotaBytes: number;
}

export function NewMailboxDrawer({ open, ...rest }: NewMailboxDrawerProps) {
    // Mounting the body only while open drops every field and the shown-once password on close.
    return open ? <Body {...rest} /> : null;
}

function Body({ onClose, domain }: Omit<NewMailboxDrawerProps, 'open'>) {
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
    // Bumped by "Add another" to remount the inputs that keep their own state (quota, Show).
    const [round, setRound] = useState(0);

    const domainName = domain ?? (picked || names.data?.[0]?.name || '');
    const unicode = names.data?.find((d) => d.name === domainName)?.unicode_name ?? domainName;

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
    const canSubmit = domainName !== '' && available && score >= 3 && quotaBytes !== null;

    useEffect(() => {
        if (created) document.getElementById(copyId)?.focus();
    }, [created, copyId]);

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
            toast.show({ kind: 'success', text: `Created ${address}.` });
            void refreshAfterMailboxWrite(queryClient);
        } catch (e) {
            const fields = fieldErrors(e);
            const mine: Partial<Record<FieldName, string>> = {};
            for (const f of FIELDS) if (fields[f] !== undefined) mine[f] = fields[f];
            setErrors(mine);
            if (Object.keys(mine).length === 0) setFormError(errorText(e, 'The mailbox could not be created.'));
        } finally {
            setBusy(false);
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
                () => toast.show({ kind: 'danger', text: 'The password could not be copied. Select it and copy it by hand.' }),
            );
    }

    const subtitle = unicode === '' ? undefined : `Domains / ${unicode} / Mailboxes`;
    // Closing while the create is pending would lose the shown-once password, so every close path
    // (Cancel, ×, Escape, backdrop) waits for the answer.
    const close = busy ? () => {} : onClose;

    if (created) {
        return (
            <Drawer open onClose={onClose} title="Mailbox created" subtitle={subtitle}>
                <div className="flex min-h-full flex-col gap-4 text-[13.5px]">
                    <div className="flex gap-2.5 rounded-lg border border-teal/30 bg-tint px-3.5 py-3">
                        <span aria-hidden="true" className="font-extrabold text-teal-dark">
                            ✓
                        </span>
                        <span className="wrap-anywhere">
                            Mailbox <b>{created.address}</b> created with a {formatBytes(created.quotaBytes)} limit.
                        </span>
                    </div>
                    <div className="rounded-[10px] border border-warn-line bg-warn-bg p-4">
                        <div className="text-xs font-bold tracking-[.06em] text-warn uppercase">Password — shown once</div>
                        <div className="mt-2.5 flex gap-2 max-md:flex-col">
                            <div className="flex h-11 min-w-0 flex-1 items-center overflow-x-auto rounded-lg border border-line bg-white px-3 font-mono text-[15px] whitespace-nowrap select-all max-md:h-12 max-md:text-base">
                                {created.password}
                            </div>
                            <Button id={copyId} variant="dark" size="lg" className="h-11 min-w-[84px]" onClick={() => copy(created.password)}>
                                {copied ? 'Copied' : 'Copy'}
                            </Button>
                        </div>
                        <p className="mt-2.5 text-[12.5px] leading-normal text-body">
                            Give this to the owner now. After you close this panel it can’t be shown again, only reset.
                        </p>
                    </div>
                    <div className="mt-auto flex flex-col gap-2">
                        <Button variant="primary" size="lg" className="h-11" onClick={onClose}>
                            Done
                        </Button>
                        <Button size="lg" className="h-11" onClick={addAnother}>
                            Add another mailbox
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
        (checkFailed ? errorText(check.error, 'The address could not be checked.') : undefined);

    return (
        <Drawer
            open
            onClose={close}
            closeDisabled={busy}
            title="New mailbox"
            subtitle={subtitle}
            footer={
                <>
                    <Button size="lg" disabled={busy} onClick={close}>
                        Cancel
                    </Button>
                    <div className="flex-1" />
                    <Button type="submit" form={formId} variant="primary" size="lg" className="min-w-[150px] max-md:flex-1" disabled={!canSubmit} busy={busy}>
                        Create mailbox
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
                    <Select label="Domain" value={domainName} disabled={!names.data} onChange={(e) => setPicked(e.target.value)}>
                        {(names.data ?? []).map((d) => (
                            <option key={d.name} value={d.name}>
                                {d.unicode_name}
                            </option>
                        ))}
                    </Select>
                )}
                <div>
                    <AddonInput
                        id={addressId}
                        label="Address"
                        domain={unicode}
                        placeholder="name"
                        autoComplete="off"
                        value={local}
                        onChange={(e) => {
                            setLocal(e.target.value);
                            clearError('local');
                        }}
                        error={addressError}
                        hint={checking ? 'Checking…' : undefined}
                        aria-describedby={addressError || checking ? `${addressId}-msg` : available ? `${addressId}-ok` : undefined}
                    />
                    {available && !addressError && (
                        <p id={`${addressId}-ok`} role="status" className="mt-1 text-[12.5px] text-teal-dark">
                            {result.message}
                        </p>
                    )}
                </div>
                <TextInput
                    label="Display name (optional)"
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
                    <QuotaInput key={`quota-${round}`} label="Storage limit" onChange={setQuota} />
                    {errors.quota_bytes && (
                        <p role="alert" className="mt-1 text-[12.5px] text-danger">
                            {errors.quota_bytes}
                        </p>
                    )}
                </div>
                {formError && (
                    <p role="alert" className="rounded-lg border border-danger-line bg-danger-bg px-3 py-2.5 text-[13px] text-danger">
                        {formError}
                    </p>
                )}
            </form>
        </Drawer>
    );
}
