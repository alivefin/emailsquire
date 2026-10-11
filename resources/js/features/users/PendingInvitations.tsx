import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiFetch } from '../../api/client';
import { isStepUpCancelled } from '../../api/problems';
import { queryKeys } from '../../api/types';
import { Button } from '../../components/ui/Button';
import { ConfirmDialog } from '../../components/ui/ConfirmDialog';
import { cx } from '../../components/ui/cx';
import { Menu, type MenuItem } from '../../components/ui/Menu';
import { Pager } from '../../components/ui/Pager';
import { useToast } from '../../components/ui/Toast';
import { formatDate, formatRelative } from '../../lib/format';
import { errorText, useSingleFlight } from '../domain/shared';
import { refreshAfterUserWrite, usersPath, type InvitationList, type ListedInvitation } from './shared';

const PER_PAGE = 10;
const panelClass = 'rounded-[10px] border border-line bg-white';
const headingClass = 'border-b border-line px-3.5 py-3 text-[15px] font-bold md:px-[18px] md:py-3.5';

/** A valid invitation is still waiting for its answer: Pending. */
function statusOf(i: ListedInvitation): 'pending' | 'expired' | 'revoked' {
    return i.state === 'valid' ? 'pending' : i.state;
}

const TONE = { pending: 'text-warn', expired: 'text-danger', revoked: 'text-muted' } as const;

type Action = 'resend' | 'revoke';

/**
 * The Users screen's "Pending invitations": every invitation nobody has accepted or revoked, to the
 * server or to any domain, with Resend and Revoke (behind a confirmation). Hidden while there are none.
 * A server-admin invitation to an address with no account shows up nowhere else.
 */
export function PendingInvitations() {
    const { t } = useTranslation(['users', 'access']);
    const queryClient = useQueryClient();
    const toast = useToast();
    const once = useSingleFlight();
    const [page, setPage] = useState(1);
    const [revoking, setRevoking] = useState<ListedInvitation | null>(null);
    // A failed resend or revoke, shown on the invitation's row.
    const [rowErrors, setRowErrors] = useState<Record<number, string>>({});

    const list = useQuery({
        queryKey: queryKeys.userInvitations({ page }),
        queryFn: () => apiFetch<InvitationList>(usersPath('invitations'), { query: { page, per_page: PER_PAGE } }),
        placeholderData: (previous) => previous,
    });

    // Past the last page once its last row is revoked: back to the last page there is.
    const lastPage = list.isPlaceholderData ? undefined : list.data?.meta.last_page;
    useEffect(() => {
        if (lastPage !== undefined && page > Math.max(1, lastPage)) setPage(Math.max(1, lastPage));
    }, [lastPage, page]);

    const action = useMutation({
        mutationFn: ({ kind, invitation }: { kind: Action; invitation: ListedInvitation }) =>
            kind === 'resend'
                ? apiFetch<unknown>(usersPath('invitations', invitation.id, 'resend'), { method: 'POST' })
                : apiFetch<undefined>(usersPath('invitations', invitation.id), { method: 'DELETE' }),
        onMutate: ({ invitation }) =>
            setRowErrors((prev) => {
                const next = { ...prev };
                delete next[invitation.id];
                return next;
            }),
        onSuccess: async (_r, { kind, invitation }) => {
            toast.show({ kind: 'success', text: t(kind === 'resend' ? 'access:resent' : 'access:revoked', { email: invitation.email }) });
            setRevoking(null);
            // The users' pages list invitations to their email, and a domain's Access tab its own.
            await refreshAfterUserWrite(queryClient, false);
        },
        onError: (e, { kind, invitation }) => {
            // A cancelled step-up is quiet; a revoke's confirmation stays open to try again.
            if (isStepUpCancelled(e)) return;
            setRevoking(null);
            const text = errorText(e, t(kind === 'resend' ? 'access:resendFailed' : 'access:revokeFailed'));
            setRowErrors((prev) => ({ ...prev, [invitation.id]: text }));
        },
    });

    const run = (kind: Action, invitation: ListedInvitation) => once(() => action.mutateAsync({ kind, invitation }));

    if (list.isError && !list.data) {
        return (
            <section aria-labelledby="pending-invitations" className={panelClass}>
                <h2 id="pending-invitations" className={headingClass}>
                    {t('pending.title')}
                </h2>
                <div role="alert" className="flex items-center gap-3 px-4 py-6 text-[13px] text-danger">
                    <span className="min-w-0 flex-1">{errorText(list.error, t('pending.loadFailed'))}</span>
                    <Button size="sm" onClick={() => void list.refetch()}>
                        {t('retry')}
                    </Button>
                </div>
            </section>
        );
    }
    if (!list.data || list.data.meta.total === 0) return null;
    const { data: rows, meta } = list.data;

    function label(i: ListedInvitation): string {
        if (i.role === 'server_admin' || i.domain_unicode === null) return t('detail.invitations.serverAdmin');
        return t(i.role === 'manager' ? 'detail.invitations.manager' : 'detail.invitations.viewer', { domain: i.domain_unicode });
    }

    function items(i: ListedInvitation): MenuItem[] {
        const revoke: MenuItem = { label: t('pending.revoke'), danger: true, disabled: action.isPending, onSelect: () => setRevoking(i) };
        // `revoked` here means its domain is gone or its inviter can no longer send it: the link is dead,
        // but the row isn't revoked yet, so Revoke still clears it. Resending would revive it in your
        // name; that is a fresh invitation's job.
        if (i.state === 'revoked') return [revoke];
        return [{ label: t('access:resend'), disabled: action.isPending, onSelect: () => run('resend', i) }, revoke];
    }

    return (
        <section aria-labelledby="pending-invitations" className={cx(panelClass, 'flex flex-col')}>
            <h2 id="pending-invitations" className={headingClass}>
                {t('pending.title')}
            </h2>
            <ul>
                {rows.map((i) => {
                    const status = statusOf(i);
                    let sent: string | null = null;
                    if (i.created_at !== null) {
                        sent =
                            i.invited_by_name === null
                                ? t('access:sent', { when: formatRelative(i.created_at) })
                                : t('detail.invitations.sentBy', { when: formatRelative(i.created_at), name: i.invited_by_name });
                    }
                    return (
                        <li key={i.id} className="flex items-start gap-3 border-b border-line-soft px-3.5 py-3 last:border-b-0 md:px-[18px]">
                            <div className="min-w-0 flex-1 text-[13.5px]">
                                <div className="font-semibold wrap-anywhere text-ink">{i.email}</div>
                                <div className="wrap-anywhere text-body">{label(i)}</div>
                                {sent !== null && <div className="text-xs text-muted">{sent}</div>}
                                <div className="mt-0.5 flex flex-wrap gap-x-1.5 text-xs">
                                    <span className={cx('font-semibold', TONE[status])}>{t(`access:status.${status}`)}</span>
                                    {status === 'pending' && (
                                        <span className="text-muted">{t('access:expires', { date: formatDate(i.expires_at) })}</span>
                                    )}
                                </div>
                                {rowErrors[i.id] !== undefined && (
                                    <p role="alert" className="mt-1 text-[12.5px] text-danger">
                                        {rowErrors[i.id]}
                                    </p>
                                )}
                            </div>
                            <Menu aria-label={t('pending.actionsFor', { email: i.email })} items={items(i)} />
                        </li>
                    );
                })}
            </ul>
            {meta.total > meta.per_page && (
                <Pager
                    className="border-t border-line px-3.5 py-3 md:px-[18px]"
                    page={meta.page}
                    perPage={meta.per_page}
                    total={meta.total}
                    onPage={setPage}
                />
            )}
            {revoking !== null && (
                <ConfirmDialog
                    tone="danger"
                    title={t('pending.revokeDialog.title', { email: revoking.email })}
                    confirm={t('pending.revokeDialog.confirm')}
                    cancel={t('cancel')}
                    busy={action.isPending}
                    error={undefined}
                    onClose={() => setRevoking(null)}
                    onConfirm={() => run('revoke', revoking)}
                >
                    {t('pending.revokeDialog.body')}
                </ConfirmDialog>
            )}
        </section>
    );
}
