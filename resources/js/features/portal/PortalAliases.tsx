import { useId, useRef, useState, type FormEvent } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { apiFetch } from '../../api/client';
import { ApiProblem, fieldErrors } from '../../api/problems';
import { AddonInput } from '../../components/ui/AddonInput';
import { Button } from '../../components/ui/Button';
import { ConfirmDialog } from '../../components/ui/ConfirmDialog';
import { useToast } from '../../components/ui/Toast';
import { LockedNote, Pending, PortalCard, PortalPage } from './PortalCard';
import {
    isSelfServiceDisabled,
    MAX_ALIASES,
    refreshPortal,
    usePortalAliases,
    usePortalMe,
    writeErrorText,
    type PortalAliases as AliasList,
    type PortalMe,
} from './usePortal';

type Alias = AliasList['data'][number];

const textClass = 'text-[13.5px] text-body max-md:text-sm';

function isUnauthenticated(e: unknown): boolean {
    return e instanceof ApiProblem && e.status === 401;
}

/**
 * `/my/aliases`: the extra addresses on the mailbox's own domain that deliver only to it. With the
 * domain's switch on, the owner adds (up to 20) and removes them; with it off, the list is read-only and
 * says to ask the administrator. The server decides whether an address is free and answers one
 * "not available" whatever the reason.
 */
export function PortalAliases() {
    const { t } = useTranslation('portal');
    const me = usePortalMe().data;
    const aliases = usePortalAliases();
    const [removing, setRemoving] = useState<Alias | null>(null);
    if (!me) return null;

    const allowed = me.self_service.aliases;
    const list = aliases.data?.data;
    const shown = (alias: Alias) => `${alias.local}@${me.domain_unicode}`;

    return (
        <PortalPage title={t('aliases.title')}>
            <PortalCard subtitle={t('aliases.subtitle')}>
                {list === undefined ? (
                    <Pending failed={aliases.isError} />
                ) : list.length === 0 ? (
                    <p className={textClass}>{t('aliases.none')}</p>
                ) : (
                    <ul aria-label={t('aliases.list')} className="flex flex-col divide-y divide-line border-y border-line">
                        {list.map((alias) => (
                            <li key={alias.local} className="flex min-h-11 items-center gap-3 py-1.5">
                                <span className="min-w-0 flex-1 text-[13.5px] font-semibold text-ink wrap-anywhere max-md:text-sm">
                                    {shown(alias)}
                                </span>
                                {allowed && (
                                    <Button
                                        size="sm"
                                        className="flex-none"
                                        aria-label={t('aliases.removeNamed', { address: shown(alias) })}
                                        onClick={() => setRemoving(alias)}
                                    >
                                        {t('aliases.remove')}
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
                {!allowed ? (
                    <LockedNote>
                        <span className="block">{t('layout.locked')}</span>
                        <span className="block">{t('aliases.askAdministrator')}</span>
                    </LockedNote>
                ) : list === undefined ? null : list.length >= MAX_ALIASES ? (
                    <p className="mt-4 text-[13px] text-muted max-md:text-sm">{t('aliases.full', { max: MAX_ALIASES })}</p>
                ) : (
                    <AddForm me={me} />
                )}
            </PortalCard>
            {removing && <RemoveDialog address={shown(removing)} local={removing.local} onClose={() => setRemoving(null)} />}
        </PortalPage>
    );
}

function AddForm({ me }: { me: PortalMe }) {
    const { t } = useTranslation('portal');
    const queryClient = useQueryClient();
    const toast = useToast();
    const fieldId = useId();
    const [local, setLocal] = useState('');
    const [error, setError] = useState<string | undefined>(undefined);
    const [busy, setBusy] = useState(false);
    const sending = useRef(false);

    const typed = local.trim().toLowerCase();

    async function submit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        if (typed === '' || sending.current) return;
        sending.current = true;
        setBusy(true);
        setError(undefined);
        try {
            const created = await apiFetch<Alias>('/portal/aliases', { method: 'POST', json: { local: typed } });
            setLocal('');
            toast.show({ kind: 'success', text: t('aliases.added', { address: `${created.local}@${me.domain_unicode}` }) });
            void refreshPortal(queryClient);
        } catch (err) {
            if (!isUnauthenticated(err)) setError(fieldErrors(err).local ?? writeErrorText(err));
            if (isSelfServiceDisabled(err)) void refreshPortal(queryClient);
        } finally {
            sending.current = false;
            setBusy(false);
        }
    }

    return (
        <form noValidate onSubmit={(e) => void submit(e)} className="mt-4 flex max-w-[560px] flex-col gap-3 sm:flex-row sm:items-start">
            <AddonInput
                id={fieldId}
                label={t('aliases.new')}
                domain={me.domain_unicode}
                autoComplete="off"
                maxLength={100}
                value={local}
                onChange={(e) => {
                    setLocal(e.target.value);
                    setError(undefined);
                }}
                error={error}
                className="min-w-0 flex-1"
            />
            <Button type="submit" variant="primary" size="lg" busy={busy} disabled={typed === ''} className="flex-none max-sm:w-full sm:mt-[25px]">
                {t('aliases.add')}
            </Button>
        </form>
    );
}

function RemoveDialog({ address, local, onClose }: { address: string; local: string; onClose: () => void }) {
    const { t } = useTranslation('portal');
    const queryClient = useQueryClient();
    const toast = useToast();
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | undefined>(undefined);
    const sending = useRef(false);

    async function remove() {
        if (sending.current) return;
        sending.current = true;
        setBusy(true);
        setError(undefined);
        try {
            await apiFetch(`/portal/aliases/${encodeURIComponent(local)}`, { method: 'DELETE' });
            toast.show({ kind: 'success', text: t('aliases.removed', { address }) });
            onClose();
            void refreshPortal(queryClient);
        } catch (err) {
            if ((err instanceof ApiProblem && err.status === 404) || isSelfServiceDisabled(err)) {
                // Already gone (removed elsewhere), or aliases were turned off meanwhile: the page
                // catches up, to the shorter list or to its locked state.
                onClose();
                void refreshPortal(queryClient);
            } else if (!isUnauthenticated(err)) {
                setError(writeErrorText(err));
            }
        } finally {
            sending.current = false;
            setBusy(false);
        }
    }

    return (
        <ConfirmDialog
            tone="danger"
            title={t('aliases.confirmTitle', { address })}
            confirm={t('aliases.remove')}
            cancel={t('aliases.cancel')}
            busy={busy}
            error={error}
            onClose={busy ? () => {} : onClose}
            onConfirm={() => void remove()}
        >
            {t('aliases.confirmBody', { address })}
        </ConfirmDialog>
    );
}
