import { useEffect, useId, useRef, useState, type FormEvent } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { apiFetch } from '../../api/client';
import { fieldErrors, isStepUpCancelled } from '../../api/problems';
import { queryKeys, type Json } from '../../api/types';
import { Button } from '../../components/ui/Button';
import { Dialog } from '../../components/ui/Dialog';
import { TextInput } from '../../components/ui/TextInput';
import { useToast } from '../../components/ui/Toast';
import { useLocale } from '../../i18n';
import { formatDate } from '../../lib/format';
import { createCredential, isWebAuthnSupported } from '../../lib/webauthn';
import { errorText } from '../domain/shared';
import { ProfileSection } from './ProfileSection';
import { RecoveryCodes } from './RecoveryCodes';

type Passkey = Json<'/me/passkeys', 'get'>['data'][number];
type Action = { kind: 'add' } | { kind: 'rename' | 'remove'; passkey: Passkey };

/** Only list metadata goes in Query. Recovery codes stay in this component's state and leave on Done/unmount. */
export function PasskeysSection() {
    const { t } = useTranslation(['profile', 'common']);
    const locale = useLocale();
    const queryClient = useQueryClient();
    const toast = useToast();
    const list = useQuery({ queryKey: queryKeys.passkeys, queryFn: ({ signal }) => apiFetch<Json<'/me/passkeys', 'get'>>('/me/passkeys', { signal }) });
    const [action, setAction] = useState<Action | null>(null);
    const [name, setName] = useState('');
    const [nameError, setNameError] = useState<string | undefined>();
    const [error, setError] = useState<string | undefined>();
    const [busy, setBusy] = useState(false);
    const [codes, setCodes] = useState<string[] | null>(null);
    const sending = useRef(false);
    const ceremony = useRef<AbortController | null>(null);
    const section = useRef<HTMLElement>(null);
    const formId = useId();
    const supported = isWebAuthnSupported();
    const passkeys = list.data?.data ?? [];
    useEffect(() => () => ceremony.current?.abort(), []);
    useEffect(() => { setError(undefined); setNameError(undefined); }, [locale]);

    function open(next: Action) {
        setAction(next);
        setName(next.kind === 'rename' ? next.passkey.name : '');
        setError(undefined);
        setNameError(undefined);
    }

    async function save(e?: FormEvent) {
        e?.preventDefault();
        if (!action || sending.current) return;
        const trimmed = name.trim();
        if (action.kind !== 'remove' && trimmed === '') { setNameError(t('passkeys.nameRequired')); return; }
        sending.current = true;
        setBusy(true);
        setError(undefined);
        setNameError(undefined);
        const controller = new AbortController();
        ceremony.current = controller;
        const signal = controller.signal;
        try {
            if (action.kind === 'add') {
                const options = await apiFetch<Json<'/me/passkeys/options', 'post'>>('/me/passkeys/options', { method: 'POST', signal });
                if (signal.aborted) return;
                const credential = await createCredential(options, signal);
                if (!credential || signal.aborted) return;
                // Direct request, never a mutation: the response may include one-time secrets.
                const answer = await apiFetch<Json<'/me/passkeys', 'post'>>('/me/passkeys', { method: 'POST', json: { name: trimmed, credential }, signal });
                if (signal.aborted) return;
                if (answer.recovery_codes) setCodes(answer.recovery_codes);
            } else if (action.kind === 'rename') {
                await apiFetch(`/me/passkeys/${action.passkey.id}`, { method: 'PATCH', json: { name: trimmed }, signal });
            } else {
                await apiFetch(`/me/passkeys/${action.passkey.id}`, { method: 'DELETE', signal });
            }
            if (signal.aborted) return;
            setAction(null);
            setName('');
            toast.show({ kind: 'success', text: t(`passkeys.${action.kind === 'add' ? 'added' : action.kind === 'rename' ? 'renamed' : 'removed'}`) });
            void queryClient.invalidateQueries({ queryKey: queryKeys.passkeys });
            void queryClient.invalidateQueries({ queryKey: queryKeys.me, exact: true });
        } catch (err) {
            if (signal.aborted || isStepUpCancelled(err)) return;
            const fields = fieldErrors(err);
            if (fields.name) setNameError(fields.name);
            else setError(fields.credential ?? errorText(err, t('passkeys.failed')));
        } finally {
            sending.current = false;
            setBusy(false);
        }
    }

    function close() {
        ceremony.current?.abort();
        setAction(null);
        setName('');
        setNameError(undefined);
        setError(undefined);
    }

    return (
        <ProfileSection ref={section} focusable title={t('passkeys.title')} subtitle={t('passkeys.text')}>
            {codes !== null ? (
                <RecoveryCodes codes={codes} fresh onDone={() => { setCodes(null); section.current?.focus(); }} />
            ) : (
                <>
                    {list.isPending && <p role="status" className="text-[13px] text-muted">{t('passkeys.loading')}</p>}
                    {list.isError && (
                        <div>
                            <p role="alert" className="text-[13px] text-danger">{errorText(list.error, t('passkeys.failed'))}</p>
                            <Button className="mt-2" onClick={() => void list.refetch()}>{t('passkeys.retry')}</Button>
                        </div>
                    )}
                    {list.isSuccess && passkeys.length === 0 && <p className="text-[13.5px] text-body">{t('passkeys.empty')}</p>}
                    {passkeys.length > 0 && (
                        <ul aria-label={t('passkeys.title')} className="divide-y divide-line">
                            {passkeys.map((passkey) => (
                                <li key={passkey.id} className="flex flex-wrap items-start justify-between gap-3 py-3 first:pt-0">
                                    <div className="min-w-0 flex-1 basis-[240px]">
                                        <p className="text-[13.5px] font-semibold wrap-anywhere text-ink">{passkey.name}</p>
                                        {passkey.disabled && <p className="mt-1 text-[12.5px] font-semibold text-warn">{t('passkeys.disabled')}</p>}
                                        <p className="mt-1 text-[12.5px] text-muted">
                                            {t('passkeys.created', { date: passkey.created_at ? formatDate(passkey.created_at, locale) : t('passkeys.unknown') })}
                                        </p>
                                        <p className="text-[12.5px] text-muted">
                                            {t('passkeys.lastUsed', { date: passkey.last_used_at ? formatDate(passkey.last_used_at, locale) : t('passkeys.never') })}
                                        </p>
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        <Button size="sm" aria-label={t('passkeys.renameNamed', { name: passkey.name })} onClick={() => open({ kind: 'rename', passkey })}>{t('passkeys.rename')}</Button>
                                        <Button size="sm" variant="danger" aria-label={t('passkeys.removeNamed', { name: passkey.name })} onClick={() => open({ kind: 'remove', passkey })}>{t('passkeys.remove')}</Button>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                    {supported ? (
                        <>
                            <Button className="mt-3" variant="primary" disabled={list.isPending || list.isError || passkeys.length >= 10} onClick={() => open({ kind: 'add' })}>{t('passkeys.add')}</Button>
                            {passkeys.length >= 10 && <p className="mt-2 text-[13px] text-muted">{t('passkeys.limit')}</p>}
                        </>
                    ) : <p className="mt-3 text-[13px] text-muted">{t('passkeys.unsupported')}</p>}
                </>
            )}
            <Dialog
                open={action !== null} size="sm" onClose={close}
                title={action?.kind === 'remove' ? t('passkeys.removeTitle') : action?.kind === 'rename' ? t('passkeys.renameTitle') : t('passkeys.add')}
                footer={<>
                    <Button disabled={busy} onClick={close}>{t('cancel')}</Button>
                    <Button type="submit" form={formId} variant={action?.kind === 'remove' ? 'danger' : 'primary'} busy={busy} disabled={action?.kind !== 'remove' && name.trim() === ''}>
                        {action?.kind === 'remove' ? t('passkeys.removeConfirm') : action?.kind === 'rename' ? t('passkeys.save') : t('passkeys.add')}
                    </Button>
                </>}
            >
                <form id={formId} noValidate onSubmit={(e) => void save(e)}>
                    {action?.kind === 'remove' ? (
                        <p className="wrap-anywhere">{t('passkeys.removeBody', { name: action.passkey.name })}</p>
                    ) : (
                        <TextInput label={t('passkeys.name')} hint={t('passkeys.nameHint')} maxLength={64} value={name} error={nameError} disabled={busy}
                            onChange={(e) => { setName(e.target.value); setNameError(undefined); setError(undefined); }} />
                    )}
                    {error !== undefined && <p role="alert" className="mt-3 text-[12.5px] text-danger">{error}</p>}
                </form>
            </Dialog>
        </ProfileSection>
    );
}
