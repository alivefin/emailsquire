import { useState, type ReactNode } from 'react';
import { useNavigate, useParams } from 'react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { apiFetch } from '../../api/client';
import { isStepUpCancelled } from '../../api/problems';
import { queryKeys } from '../../api/types';
import { Button } from '../../components/ui/Button';
import { cx } from '../../components/ui/cx';
import { ConfirmDialog } from '../../components/ui/ConfirmDialog';
import { EmptyState } from '../../components/ui/EmptyState';
import { Menu, type MenuItem } from '../../components/ui/Menu';
import { useToast } from '../../components/ui/Toast';
import { formatDate, formatDay, formatRelative } from '../../lib/format';
import { canShare } from '../../lib/roles';
import { useDomainNames } from '../../layout/useNavData';
import { useMe } from '../auth/useMe';
import { errorText, useSingleFlight } from '../domain/shared';
import { ShareDialog } from './ShareDialog';
import {
    accessPath,
    refreshAfterAccessWrite,
    type AccessInvitation,
    type AccessList,
    type AccessPerson,
    type GrantRole,
} from './shared';

const panelClass = 'rounded-[10px] border border-line bg-white';

/**
 * One grid for every width. Phone: the person and the menu on the first line, the role under them.
 * Tablet: person, role (with the status under it), menu. From 1280 px: the prototype's five columns.
 */
const rowGrid =
    'grid grid-cols-[minmax(0,1fr)_auto] gap-x-2 gap-y-1.5 md:grid-cols-[minmax(0,1fr)_150px_44px] md:items-center md:gap-x-3 ' +
    'xl:grid-cols-[minmax(0,1fr)_150px_190px_150px_56px]';

const selectClass =
    'h-8 w-full max-w-[150px] rounded-md border border-line-strong bg-white px-2 text-[13px] text-ink outline-none ' +
    'focus:border-teal focus:ring-2 focus:ring-teal/20 max-md:h-11 max-md:text-base';

/** A row of the list: a person with a grant, or a pending invitation. */
type Row = { kind: 'person'; person: AccessPerson } | { kind: 'invitation'; invitation: AccessInvitation };

/** What the confirmation dialog is asking about. */
type Confirm = { kind: 'role'; person: AccessPerson; role: GrantRole } | { kind: 'remove'; person: AccessPerson } | null;

function rowKey(r: Row): string {
    return r.kind === 'person' ? `p${r.person.id}` : `i${r.invitation.id}`;
}

/** A person's name, or their email when the name is empty. */
function personName(p: AccessPerson): string {
    return p.name.trim() === '' ? p.email : p.name;
}

/** The domain page's Access tab (`/domains/:domain/access`): who has access, and sharing it. */
export function AccessTab() {
    const { t } = useTranslation('access');
    const { domain = '' } = useParams();
    const me = useMe();
    const names = useDomainNames();
    const queryClient = useQueryClient();
    const toast = useToast();
    const once = useSingleFlight();

    const named = names.data?.find((d) => d.name === domain);
    const unicode = named?.unicode_name ?? domain;
    const role = named?.role;
    // Hidden, not disabled, for a role that can't share; hidden too until the role is known.
    const sharing = canShare(role, me.data);
    const myId = me.data?.id;

    const [shareOpen, setShareOpen] = useState(false);
    const [confirm, setConfirm] = useState<Confirm>(null);
    // A failed resend or revoke, shown on the invitation's row.
    const [rowErrors, setRowErrors] = useState<Record<string, string>>({});

    const list = useQuery({
        queryKey: queryKeys.access(domain),
        queryFn: () => apiFetch<AccessList>(accessPath(domain)),
    });

    const invitationAction = useMutation({
        mutationFn: ({ action, invitation }: { action: 'resend' | 'revoke'; invitation: AccessInvitation }) =>
            action === 'resend'
                ? apiFetch<unknown>(accessPath(domain, 'invitations', invitation.id, 'resend'), { method: 'POST' })
                : apiFetch<undefined>(accessPath(domain, 'invitations', invitation.id), { method: 'DELETE' }),
        onMutate: ({ invitation }) => setRowErrors((prev) => withoutKey(prev, `i${invitation.id}`)),
        onSuccess: async (_r, { action, invitation }) => {
            toast.show({ kind: 'success', text: t(action === 'resend' ? 'resent' : 'revoked', { email: invitation.email }) });
            await refreshAfterAccessWrite(queryClient);
        },
        onError: (e, { action, invitation }) => {
            if (isStepUpCancelled(e)) return;
            const text = errorText(e, t(action === 'resend' ? 'resendFailed' : 'revokeFailed'));
            setRowErrors((prev) => ({ ...prev, [`i${invitation.id}`]: text }));
        },
    });

    const rows: Row[] = list.data
        ? [
              ...list.data.people.map((person): Row => ({ kind: 'person', person })),
              ...list.data.invitations.map((invitation): Row => ({ kind: 'invitation', invitation })),
          ]
        : [];

    function menuItems(r: Row): MenuItem[] {
        if (r.kind === 'person') {
            const { person } = r;
            // Everyone may leave a domain; removing someone else needs sharing.
            if (person.id === myId) return [{ label: t('removeSelf'), danger: true, onSelect: () => setConfirm({ kind: 'remove', person }) }];
            return sharing ? [{ label: t('remove'), danger: true, onSelect: () => setConfirm({ kind: 'remove', person }) }] : [];
        }
        if (!sharing) return [];
        const { invitation } = r;
        const run = (action: 'resend' | 'revoke') => once(() => invitationAction.mutateAsync({ action, invitation }));
        return [
            { label: t('resend'), onSelect: () => run('resend'), disabled: invitationAction.isPending },
            { label: t('revoke'), danger: true, onSelect: () => run('revoke'), disabled: invitationAction.isPending },
        ];
    }

    const shareButton = sharing ? (
        <Button variant="primary" onClick={() => setShareOpen(true)}>
            {t('share')}
        </Button>
    ) : null;

    // The list waits for the role as well, so no control shows before it is known to be allowed.
    const loading = list.isPending || names.isPending || me.isPending;
    const failed = list.isError && !list.data;

    let body: ReactNode;
    if (failed) {
        body = (
            <div role="alert" className="flex items-center gap-3 px-4 py-6 text-[13px] text-danger">
                <span className="min-w-0 flex-1">{errorText(list.error, t('loadFailed'))}</span>
                <Button size="sm" onClick={() => void list.refetch()}>
                    {t('retry')}
                </Button>
            </div>
        );
    } else if (loading) {
        body = (
            <p aria-busy="true" className="px-[18px] py-6 text-[13px] text-muted">
                {t('loading')}
            </p>
        );
    } else if (rows.length === 0) {
        body =
            role === 'admin' ? (
                <EmptyState title={t('empty.title')} action={shareButton}>
                    {t('empty.hint', { domain: unicode })}
                </EmptyState>
            ) : (
                <p className="px-[18px] py-6 text-[13px] text-muted">{t('empty.none', { domain: unicode })}</p>
            );
    } else {
        body = (
            <>
                <div
                    aria-hidden="true"
                    className={cx(rowGrid, 'border-b border-line bg-subtle px-[18px] py-2.5 text-[12.5px] font-semibold text-body max-md:hidden')}
                >
                    <div>{t('columns.person')}</div>
                    <div>{t('columns.role')}</div>
                    <div className="hidden xl:block">{t('columns.added')}</div>
                    <div className="hidden xl:block">{t('columns.status')}</div>
                    <div />
                </div>
                <ul>
                    {rows.map((r) => (
                        <AccessRow
                            key={rowKey(r)}
                            row={r}
                            self={r.kind === 'person' && r.person.id === myId}
                            sharing={sharing}
                            items={menuItems(r)}
                            error={rowErrors[rowKey(r)]}
                            onRole={(person, next) => setConfirm({ kind: 'role', person, role: next })}
                        />
                    ))}
                </ul>
            </>
        );
    }

    return (
        <div className={cx('flex flex-col', panelClass)}>
            <div className="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-line px-3.5 py-3 md:px-[18px] md:py-3.5">
                <h2 className="min-w-0 flex-1 text-[15px] font-bold wrap-anywhere">{t('title', { domain: unicode })}</h2>
                {!loading && rows.length > 0 && shareButton}
            </div>
            {body}
            <ShareDialog open={shareOpen && sharing} onClose={() => setShareOpen(false)} domain={domain} unicode={unicode} />
            {confirm?.kind === 'role' && (
                <RoleDialog domain={domain} unicode={unicode} person={confirm.person} role={confirm.role} onClose={() => setConfirm(null)} />
            )}
            {confirm?.kind === 'remove' && (
                <RemoveDialog
                    domain={domain}
                    unicode={unicode}
                    person={confirm.person}
                    self={confirm.person.id === myId}
                    onClose={() => setConfirm(null)}
                />
            )}
        </div>
    );
}

function withoutKey(record: Record<string, string>, key: string): Record<string, string> {
    if (!(key in record)) return record;
    const next = { ...record };
    delete next[key];
    return next;
}

interface AccessRowProps {
    row: Row;
    self: boolean;
    sharing: boolean;
    items: MenuItem[];
    error: string | undefined;
    onRole: (person: AccessPerson, role: GrantRole) => void;
}

function AccessRow({ row, self, sharing, items, error, onRole }: AccessRowProps) {
    const { t } = useTranslation('access');
    const person = row.kind === 'person' ? row.person : null;
    const invitation = row.kind === 'invitation' ? row.invitation : null;
    const name = person ? personName(person) : invitation!.email;
    const role = person ? person.role : invitation!.role;

    // The email under a person's name; when an invitation was sent under its address.
    let detail: string | null = null;
    if (person && name !== person.email) detail = person.email;
    if (invitation?.created_at) detail = t('sent', { when: formatRelative(invitation.created_at) });

    const by = person ? person.granted_by_name : invitation!.invited_by_name;
    const at = person ? person.granted_at : invitation!.created_at;
    const added = by !== null && at !== null ? t('addedBy', { name: by, date: formatDay(at) }) : at !== null ? formatDay(at) : by;

    const roleControl =
        person && sharing && !self ? (
            <select
                aria-label={t('roleOf', { name })}
                value={person.role}
                onChange={(e) => onRole(person, e.target.value === 'manager' ? 'manager' : 'viewer')}
                className={selectClass}
            >
                <option value="manager">{t('roles.manager')}</option>
                <option value="viewer">{t('roles.viewer')}</option>
            </select>
        ) : (
            <span className="text-body">{t(`roles.${role}`)}</span>
        );

    return (
        <li className={cx(rowGrid, 'border-b border-line-soft px-3.5 py-3 text-[13.5px] last:border-b-0 md:px-[18px] md:py-2.5')}>
            <div className="min-w-0 max-md:col-start-1 max-md:row-start-1">
                <div className="font-semibold wrap-anywhere text-ink">
                    <span data-person="">{name}</span>
                    {self && <span className="ml-1.5 text-[11px] font-medium text-muted">{t('you')}</span>}
                </div>
                {detail !== null && <div className="text-xs wrap-anywhere text-muted">{detail}</div>}
                {added !== null && <div className="text-xs text-muted xl:hidden">{added}</div>}
                {error !== undefined && (
                    <p role="alert" className="mt-1 text-[12.5px] text-danger">
                        {error}
                    </p>
                )}
            </div>
            <div className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 max-md:col-start-1 md:flex-col md:items-start md:gap-0.5">
                {roleControl}
                <span className="text-xs xl:hidden">
                    <Status person={person} invitation={invitation} />
                </span>
            </div>
            <div className="hidden text-[13px] text-muted xl:block">{added ?? '—'}</div>
            <div className="hidden xl:block">
                <Status person={person} invitation={invitation} />
            </div>
            <div className="max-md:col-start-2 max-md:row-start-1 md:text-right">
                {items.length > 0 && <Menu aria-label={t('actionsFor', { name })} items={items} />}
            </div>
        </li>
    );
}

const STATUS_TONE = {
    active: 'text-teal-dark',
    deactivated: 'text-muted',
    pending: 'text-warn',
    expired: 'text-danger',
    revoked: 'text-muted',
} as const;

function Status({ person, invitation }: { person: AccessPerson | null; invitation: AccessInvitation | null }) {
    const { t } = useTranslation('access');
    const status = person ? person.status : invitation!.state === 'valid' ? 'pending' : invitation!.state;
    return (
        <span className="inline-flex flex-wrap gap-x-1.5">
            <span className={cx('font-semibold', STATUS_TONE[status])}>{t(`status.${status}`)}</span>
            {status === 'pending' && (
                <span className="font-normal text-muted">{t('expires', { date: formatDate(invitation!.expires_at) })}</span>
            )}
        </span>
    );
}

interface ConfirmProps {
    domain: string;
    unicode: string;
    person: AccessPerson;
    onClose: () => void;
}

function RoleDialog({ domain, unicode, person, role, onClose }: ConfirmProps & { role: GrantRole }) {
    const { t } = useTranslation('access');
    const queryClient = useQueryClient();
    const toast = useToast();
    const once = useSingleFlight();
    const [error, setError] = useState<string | undefined>();
    const name = personName(person);

    const change = useMutation({
        mutationFn: () => apiFetch<AccessPerson>(accessPath(domain, person.id), { method: 'PATCH', json: { role } }),
        onSuccess: async () => {
            toast.show({ kind: 'success', text: t(role === 'manager' ? 'roleDialog.changedManager' : 'roleDialog.changedViewer', { name }) });
            onClose();
            await refreshAfterAccessWrite(queryClient);
        },
        onError: (e) => {
            if (!isStepUpCancelled(e)) setError(errorText(e, t('roleDialog.failed')));
        },
    });

    return (
        <ConfirmDialog
            tone="primary"
            title={t(role === 'manager' ? 'roleDialog.toManager' : 'roleDialog.toViewer', { name })}
            confirm={t('roleDialog.confirm')}
            cancel={t('roleDialog.cancel')}
            busy={change.isPending}
            error={error}
            onClose={onClose}
            onConfirm={() => {
                setError(undefined);
                once(() => change.mutateAsync());
            }}
        >
            {t(role === 'manager' ? 'roleDialog.managerBody' : 'roleDialog.viewerBody', { name, domain: unicode })}
        </ConfirmDialog>
    );
}

function RemoveDialog({ domain, unicode, person, self, onClose }: ConfirmProps & { self: boolean }) {
    const { t } = useTranslation('access');
    const queryClient = useQueryClient();
    const navigate = useNavigate();
    const toast = useToast();
    const once = useSingleFlight();
    const [error, setError] = useState<string | undefined>();
    const name = personName(person);

    const remove = useMutation({
        mutationFn: () => apiFetch<undefined>(accessPath(domain, person.id), { method: 'DELETE' }),
        onSuccess: async () => {
            if (!self) {
                toast.show({ kind: 'success', text: t('removeDialog.removed', { name }) });
                onClose();
                await refreshAfterAccessWrite(queryClient);
                return;
            }
            // The domain is no longer ours: leave it first, so nothing on its page asks for it again.
            toast.show({ kind: 'success', text: t('removeDialog.selfRemoved', { domain: unicode }) });
            void navigate('/', { replace: true });
            await Promise.all([
                // Everything but the domain's own queries, which would only answer 404 now.
                queryClient.invalidateQueries({ queryKey: ['domains'], predicate: (q) => q.queryKey[1] !== domain }),
                // `landing` may have changed.
                queryClient.invalidateQueries({ queryKey: queryKeys.me }),
                queryClient.invalidateQueries({ queryKey: queryKeys.pinned }),
                queryClient.invalidateQueries({ queryKey: queryKeys.dashboard }),
                queryClient.invalidateQueries({ queryKey: ['addresses'] }),
                queryClient.invalidateQueries({ queryKey: ['search'] }),
            ]);
        },
        onError: (e) => {
            if (!isStepUpCancelled(e)) setError(errorText(e, t('removeDialog.failed')));
        },
    });

    return (
        <ConfirmDialog
            tone="danger"
            title={self ? t('removeDialog.selfTitle', { domain: unicode }) : t('removeDialog.title', { name })}
            confirm={t('removeDialog.confirm')}
            cancel={t('removeDialog.cancel')}
            busy={remove.isPending}
            error={error}
            onClose={onClose}
            onConfirm={() => {
                setError(undefined);
                once(() => remove.mutateAsync());
            }}
        >
            {self ? t('removeDialog.selfBody', { domain: unicode }) : t('removeDialog.body', { name, domain: unicode })}
        </ConfirmDialog>
    );
}
