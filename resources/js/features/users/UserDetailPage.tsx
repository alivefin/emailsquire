import { useEffect, useId, useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Trans, useTranslation } from 'react-i18next';
import { Link, useNavigate, useParams } from 'react-router';
import { apiFetch } from '../../api/client';
import { ApiProblem, fieldErrors, isStepUpCancelled } from '../../api/problems';
import { queryKeys } from '../../api/types';
import { Button } from '../../components/ui/Button';
import { ConfirmDialog } from '../../components/ui/ConfirmDialog';
import { cx } from '../../components/ui/cx';
import { Dialog } from '../../components/ui/Dialog';
import { Menu } from '../../components/ui/Menu';
import { Switch } from '../../components/ui/Switch';
import { TextInput } from '../../components/ui/TextInput';
import { useToast } from '../../components/ui/Toast';
import { TypedConfirmDialog, typedNameClass } from '../../components/ui/TypedConfirmDialog';
import { formatDate, formatRelative } from '../../lib/format';
import { useMe } from '../auth/useMe';
import { errorText, useSingleFlight } from '../domain/shared';
import { ProfileSection } from '../profile/ProfileSection';
import { StatusBadge } from './Badges';
import { refreshAfterUserWrite, userName, usersPath, type UserDetail, type UserInvitation } from './shared';
import { DomainsCard } from './UserDomainsSection';
import { useUserWrite } from './userWrite';

/** `/users/:id` (server admins): one console user, their role, status, two-factor, domains and invitations. */
export function UserDetailPage() {
    const { t } = useTranslation('users');
    const { id = '' } = useParams();
    const me = useMe().data;

    const query = useQuery({
        queryKey: queryKeys.user(id),
        queryFn: () => apiFetch<UserDetail>(usersPath(id)),
        // A user who doesn't exist won't appear by asking again.
        retry: (count, e) => !(e instanceof ApiProblem && e.status === 404) && count < 3,
    });

    const back = (
        <Link to="/users" className="text-[13px] font-semibold text-teal-dark hover:underline">
            <span aria-hidden="true">← </span>
            {t('detail.back')}
        </Link>
    );

    if (query.isPending) {
        return (
            <div className="mx-auto flex w-full max-w-[760px] flex-col gap-4">
                {back}
                <div aria-busy="true" className="h-40 animate-pulse rounded-[10px] border border-line bg-line-soft" />
            </div>
        );
    }

    if (query.isError && !query.data) {
        const missing = query.error instanceof ApiProblem && query.error.status === 404;
        return (
            <div className="mx-auto flex w-full max-w-[760px] flex-col gap-4">
                {back}
                {missing ? (
                    <h2 className="text-lg font-bold">{t('detail.notFound')}</h2>
                ) : (
                    <div role="alert" className="flex items-center gap-3 text-[13px] text-danger">
                        <span className="min-w-0 flex-1">{errorText(query.error, t('detail.loadFailed'))}</span>
                        <Button size="sm" onClick={() => void query.refetch()}>
                            {t('retry')}
                        </Button>
                    </div>
                )}
            </div>
        );
    }

    const user = query.data;
    const self = me?.id === user.id;

    return (
        <div className="mx-auto flex w-full max-w-[760px] flex-col gap-4">
            <div className="flex flex-col gap-1">
                {back}
                <h2 className="text-xl font-bold wrap-anywhere text-ink">
                    {userName(user)}
                    {self && <span className="ml-2 text-[12px] font-medium text-muted">{t('you')}</span>}
                </h2>
            </div>
            <ProfileCard user={user} self={self} />
            <RoleCard user={user} self={self} />
            <StatusCard user={user} self={self} />
            <TwoFactorCard user={user} self={self} />
            <DomainsCard user={user} />
            {user.invitations.length > 0 && <InvitationsCard user={user} />}
            {!self && <DangerCard user={user} />}
        </div>
    );
}

interface CardProps {
    user: UserDetail;
    self: boolean;
}

function patchUser(user: UserDetail, json: Record<string, unknown>) {
    return apiFetch<unknown>(usersPath(user.id), { method: 'PATCH', json });
}

function ProfileCard({ user, self }: CardProps) {
    const { t } = useTranslation('users');
    const [name, setName] = useState(user.name);
    const [nameError, setNameError] = useState<string | undefined>();
    const [emailOpen, setEmailOpen] = useState(false);
    const write = useUserWrite(user, self, (json: { name: string }) => patchUser(user, json), t('detail.profile.failed'));

    // A refetch (or another tab's change) brings a new name: show it.
    useEffect(() => setName(user.name), [user.name]);

    const trimmed = name.trim();
    const error = nameError ?? write.error;

    function save(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        if (trimmed === '') {
            setNameError(t('detail.profile.nameRequired'));
            return;
        }
        if (trimmed === user.name) return;
        write.send({ name: trimmed }, t('detail.profile.saved'));
    }

    return (
        <ProfileSection title={t('detail.profile.title')}>
            <div className="flex max-w-[460px] flex-col gap-3.5">
                <form noValidate onSubmit={save}>
                    <TextInput
                        label={t('detail.profile.name')}
                        value={name}
                        maxLength={100}
                        autoComplete="off"
                        onChange={(e) => {
                            setName(e.target.value);
                            setNameError(undefined);
                            write.clear();
                        }}
                        error={error}
                    />
                    <Button type="submit" className="mt-2.5" busy={write.busy} disabled={trimmed === user.name}>
                        {t('detail.profile.save')}
                    </Button>
                </form>
                <div>
                    <div className="mb-1.5 text-[13px] font-semibold text-ink">{t('detail.profile.email')}</div>
                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                        <span className="min-w-0 text-[13.5px] wrap-anywhere text-body">{user.email}</span>
                        <Button size="sm" aria-label={t('detail.profile.changeEmail')} onClick={() => setEmailOpen(true)}>
                            {t('detail.profile.change')}
                        </Button>
                    </div>
                </div>
            </div>
            {emailOpen && <EmailDialog user={user} self={self} onClose={() => setEmailOpen(false)} />}
        </ProfileSection>
    );
}

function EmailDialog({ user, self, onClose }: CardProps & { onClose: () => void }) {
    const { t } = useTranslation('users');
    const queryClient = useQueryClient();
    const toast = useToast();
    const once = useSingleFlight();
    const formId = useId();
    const [email, setEmail] = useState('');
    const [error, setError] = useState<string | undefined>();
    const [formError, setFormError] = useState<string | undefined>();

    const change = useMutation({
        mutationFn: (next: string) => apiFetch<{ email: string }>(usersPath(user.id), { method: 'PATCH', json: { email: next } }),
        onSuccess: async (updated, next) => {
            toast.show({ kind: 'success', text: t('detail.emailDialog.changed', { email: updated.email ?? next }) });
            onClose();
            await refreshAfterUserWrite(queryClient, self);
        },
        onError: (e) => {
            if (isStepUpCancelled(e)) return;
            const field = fieldErrors(e).email;
            if (field !== undefined) setError(field);
            else setFormError(errorText(e, t('detail.emailDialog.failed')));
        },
    });

    function submit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        const next = email.trim();
        setFormError(undefined);
        if (next === '') {
            setError(t('detail.emailDialog.required'));
            return;
        }
        if (next.toLowerCase() === user.email.toLowerCase()) {
            setError(t('detail.emailDialog.same'));
            return;
        }
        setError(undefined);
        once(() => change.mutateAsync(next));
    }

    return (
        <Dialog
            open
            onClose={onClose}
            title={t('detail.emailDialog.title', { name: userName(user) })}
            footer={
                <>
                    <Button onClick={onClose}>{t('cancel')}</Button>
                    <Button type="submit" form={formId} variant="primary" busy={change.isPending}>
                        {t('detail.emailDialog.confirm')}
                    </Button>
                </>
            }
        >
            <p className="leading-relaxed">{t('detail.emailDialog.body')}</p>
            <form id={formId} noValidate onSubmit={submit} className="mt-4">
                <TextInput
                    label={t('detail.emailDialog.label')}
                    type="email"
                    autoComplete="off"
                    autoCapitalize="none"
                    spellCheck={false}
                    value={email}
                    onChange={(e) => {
                        setEmail(e.target.value);
                        setError(undefined);
                    }}
                    error={error}
                />
                {formError && (
                    <p role="alert" className="mt-3 text-[12.5px] text-danger">
                        {formError}
                    </p>
                )}
            </form>
        </Dialog>
    );
}

function RoleCard({ user, self }: CardProps) {
    const { t } = useTranslation('users');
    const [asking, setAsking] = useState(false);
    const name = userName(user);
    const promote = !user.is_server_admin;
    const write = useUserWrite(user, self, (json: { is_server_admin: boolean }) => patchUser(user, json), t('detail.roleDialog.failed'));

    return (
        <ProfileSection title={t('detail.role.title')}>
            {self ? (
                <p className="text-[13.5px] text-body">{t('detail.role.self')}</p>
            ) : (
                <>
                    <Switch
                        label={t('detail.role.switch')}
                        checked={user.is_server_admin}
                        onChange={() => {
                            write.clear();
                            setAsking(true);
                        }}
                    />
                    <p className="mt-1.5 text-[12.5px] text-muted">{t('detail.role.hint')}</p>
                </>
            )}
            {asking && (
                <ConfirmDialog
                    tone="primary"
                    title={t(promote ? 'detail.roleDialog.promoteTitle' : 'detail.roleDialog.demoteTitle', { name })}
                    confirm={t(promote ? 'detail.roleDialog.promote' : 'detail.roleDialog.demote')}
                    cancel={t('cancel')}
                    busy={write.busy}
                    error={write.error}
                    onClose={() => setAsking(false)}
                    onConfirm={() =>
                        write.send(
                            { is_server_admin: promote },
                            t(promote ? 'detail.roleDialog.promoted' : 'detail.roleDialog.demoted', { name }),
                            () => setAsking(false),
                        )
                    }
                >
                    {t(promote ? 'detail.roleDialog.promoteBody' : 'detail.roleDialog.demoteBody', { name })}
                </ConfirmDialog>
            )}
        </ProfileSection>
    );
}

function StatusCard({ user, self }: CardProps) {
    const { t } = useTranslation('users');
    const [asking, setAsking] = useState(false);
    const name = userName(user);
    const active = user.status === 'active';
    const write = useUserWrite(user, self, (json: { status: 'active' | 'deactivated' }) => patchUser(user, json), t('detail.statusDialog.failed'));

    return (
        <ProfileSection title={t('detail.status.title')} aside={<StatusBadge status={user.status} />}>
            <div className="flex flex-wrap items-center gap-x-4 gap-y-2.5">
                <p className="min-w-0 flex-1 text-[13.5px] text-body">
                    {t(active ? 'detail.status.activeText' : 'detail.status.deactivatedText')}
                </p>
                {!self && (
                    <Button
                        variant={active ? 'danger' : 'secondary'}
                        onClick={() => {
                            write.clear();
                            setAsking(true);
                        }}
                    >
                        {t(active ? 'detail.status.deactivate' : 'detail.status.reactivate')}
                    </Button>
                )}
            </div>
            {asking && (
                <ConfirmDialog
                    tone={active ? 'danger' : 'primary'}
                    title={t(active ? 'detail.statusDialog.deactivateTitle' : 'detail.statusDialog.reactivateTitle', { name })}
                    confirm={t(active ? 'detail.statusDialog.deactivate' : 'detail.statusDialog.reactivate')}
                    cancel={t('cancel')}
                    busy={write.busy}
                    error={write.error}
                    onClose={() => setAsking(false)}
                    onConfirm={() =>
                        write.send(
                            { status: active ? 'deactivated' : 'active' },
                            t(active ? 'detail.statusDialog.deactivated' : 'detail.statusDialog.reactivated', { name }),
                            () => setAsking(false),
                        )
                    }
                >
                    {t(active ? 'detail.statusDialog.deactivateBody' : 'detail.statusDialog.reactivateBody', { name })}
                </ConfirmDialog>
            )}
        </ProfileSection>
    );
}

function TwoFactorCard({ user, self }: CardProps) {
    const { t } = useTranslation('users');
    const [asking, setAsking] = useState(false);
    const name = userName(user);
    const on = user.two_factor_confirmed;
    const write = useUserWrite(
        user,
        self,
        () => apiFetch<undefined>(usersPath(user.id, 'two-factor', 'reset'), { method: 'POST' }),
        t('detail.resetDialog.failed'),
    );

    const badge = (
        <span
            className={cx(
                'rounded-full px-2.5 py-0.5 text-[12.5px] font-semibold',
                on ? 'bg-tint text-teal-dark' : 'bg-subtle text-muted',
            )}
        >
            {t(on ? 'detail.twoFactor.on' : 'detail.twoFactor.off')}
        </span>
    );

    return (
        <ProfileSection title={t('detail.twoFactor.title')} aside={badge}>
            <div className="flex flex-wrap items-center gap-x-4 gap-y-2.5">
                <p className="min-w-0 flex-1 text-[13.5px] text-body">{t(on ? 'detail.twoFactor.onText' : 'detail.twoFactor.offText')}</p>
                {!self && on && (
                    <Button
                        onClick={() => {
                            write.clear();
                            setAsking(true);
                        }}
                    >
                        {t('detail.twoFactor.reset')}
                    </Button>
                )}
            </div>
            {asking && (
                <ConfirmDialog
                    tone="danger"
                    title={t('detail.resetDialog.title', { name })}
                    confirm={t('detail.resetDialog.confirm')}
                    cancel={t('cancel')}
                    busy={write.busy}
                    error={write.error}
                    onClose={() => setAsking(false)}
                    onConfirm={() => write.send(undefined, t('detail.resetDialog.done', { name }), () => setAsking(false))}
                >
                    {t('detail.resetDialog.body')}
                </ConfirmDialog>
            )}
        </ProfileSection>
    );
}

/** A valid invitation is still waiting for its answer: Pending. */
function invitationStatus(i: UserInvitation): 'pending' | 'expired' | 'revoked' {
    return i.state === 'valid' ? 'pending' : i.state;
}

const INVITATION_TONE = { pending: 'text-warn', expired: 'text-danger', revoked: 'text-muted' } as const;

function InvitationsCard({ user }: { user: UserDetail }) {
    const { t } = useTranslation(['users', 'access']);
    const queryClient = useQueryClient();
    const toast = useToast();
    const once = useSingleFlight();
    const [rowErrors, setRowErrors] = useState<Record<number, string>>({});

    const action = useMutation({
        mutationFn: ({ kind, invitation }: { kind: 'resend' | 'revoke'; invitation: UserInvitation }) =>
            kind === 'resend'
                ? apiFetch<unknown>(usersPath('invitations', invitation.id, 'resend'), { method: 'POST' })
                : apiFetch<undefined>(usersPath('invitations', invitation.id), { method: 'DELETE' }),
        onMutate: ({ invitation }) =>
            setRowErrors((prev) => {
                const next = { ...prev };
                delete next[invitation.id];
                return next;
            }),
        onSuccess: async (_r, { kind }) => {
            toast.show({ kind: 'success', text: t(kind === 'resend' ? 'access:resent' : 'access:revoked', { email: user.email }) });
            await refreshAfterUserWrite(queryClient, false);
        },
        onError: (e, { kind, invitation }) => {
            if (isStepUpCancelled(e)) return;
            const text = errorText(e, t(kind === 'resend' ? 'access:resendFailed' : 'access:revokeFailed'));
            setRowErrors((prev) => ({ ...prev, [invitation.id]: text }));
        },
    });

    function label(i: UserInvitation): string {
        if (i.role === 'server_admin' || i.domain_unicode === null) return t('detail.invitations.serverAdmin');
        return t(i.role === 'manager' ? 'detail.invitations.manager' : 'detail.invitations.viewer', { domain: i.domain_unicode });
    }

    return (
        <ProfileSection title={t('detail.invitations.title')}>
            <ul className="-mx-5 border-t border-line max-md:-mx-4">
                {user.invitations.map((i) => {
                    const status = invitationStatus(i);
                    const role = label(i);
                    const sent = i.created_at === null ? null : t('access:sent', { when: formatRelative(i.created_at) });
                    return (
                        <li key={i.id} className="flex items-start gap-3 border-b border-line-soft px-5 py-3 last:border-b-0 max-md:px-4">
                            <div className="min-w-0 flex-1 text-[13.5px]">
                                <div className="font-semibold wrap-anywhere text-ink">{role}</div>
                                {sent !== null && (
                                    <div className="text-xs text-muted">
                                        {i.invited_by_name === null || i.created_at === null
                                            ? sent
                                            : t('detail.invitations.sentBy', { when: formatRelative(i.created_at), name: i.invited_by_name })}
                                    </div>
                                )}
                                <div className="mt-0.5 flex flex-wrap gap-x-1.5 text-xs">
                                    <span className={cx('font-semibold', INVITATION_TONE[status])}>{t(`access:status.${status}`)}</span>
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
                            <Menu
                                aria-label={t('detail.invitations.actionsFor', { role })}
                                items={[
                                    {
                                        label: t('access:resend'),
                                        disabled: action.isPending,
                                        onSelect: () => once(() => action.mutateAsync({ kind: 'resend', invitation: i })),
                                    },
                                    {
                                        label: t('access:revoke'),
                                        danger: true,
                                        disabled: action.isPending,
                                        onSelect: () => once(() => action.mutateAsync({ kind: 'revoke', invitation: i })),
                                    },
                                ]}
                            />
                        </li>
                    );
                })}
            </ul>
        </ProfileSection>
    );
}

function DangerCard({ user }: { user: UserDetail }) {
    const { t } = useTranslation('users');
    const queryClient = useQueryClient();
    const navigate = useNavigate();
    const toast = useToast();
    const once = useSingleFlight();
    const [open, setOpen] = useState(false);
    const [error, setError] = useState<string | undefined>();
    const name = userName(user);

    const remove = useMutation({
        mutationFn: () => apiFetch<undefined>(usersPath(user.id), { method: 'DELETE' }),
        onSuccess: async () => {
            toast.show({ kind: 'success', text: t('detail.deleteDialog.deleted', { name }) });
            // Leave the page first, so nothing on it asks for the user again.
            void navigate('/users', { replace: true });
            queryClient.removeQueries({ queryKey: queryKeys.user(user.id) });
            await refreshAfterUserWrite(queryClient, false);
        },
        onError: (e) => {
            if (!isStepUpCancelled(e)) setError(errorText(e, t('detail.deleteDialog.failed')));
        },
    });

    return (
        <ProfileSection title={t('detail.danger.title')}>
            <div className="flex flex-wrap items-center gap-x-4 gap-y-2.5">
                <p className="min-w-0 flex-1 text-[13.5px] text-body">{t('detail.danger.text')}</p>
                <Button
                    variant="danger"
                    onClick={() => {
                        setError(undefined);
                        setOpen(true);
                    }}
                >
                    {t('detail.danger.delete')}
                </Button>
            </div>
            {open && (
                <TypedConfirmDialog
                    onClose={() => setOpen(false)}
                    title={t('detail.deleteDialog.title', { name })}
                    prompt={
                        <Trans
                            t={t}
                            i18nKey="detail.deleteDialog.typeToConfirm"
                            values={{ email: user.email }}
                            components={[<span key="email" className={typedNameClass} />]}
                        />
                    }
                    matches={(text) => text.trim().toLowerCase() === user.email.toLowerCase()}
                    confirm={t('detail.deleteDialog.confirm')}
                    cancel={t('cancel')}
                    busy={remove.isPending}
                    error={error}
                    onEdit={() => setError(undefined)}
                    onConfirm={() => {
                        setError(undefined);
                        once(() => remove.mutateAsync());
                    }}
                >
                    <p className="leading-relaxed">{t('detail.deleteDialog.body')}</p>
                </TypedConfirmDialog>
            )}
        </ProfileSection>
    );
}
