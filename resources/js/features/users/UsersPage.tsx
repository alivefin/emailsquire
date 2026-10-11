import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useSearchParams } from 'react-router';
import { useQuery } from '@tanstack/react-query';
import { apiFetch } from '../../api/client';
import { queryKeys } from '../../api/types';
import { Button } from '../../components/ui/Button';
import { cx } from '../../components/ui/cx';
import { DataTable, type DataTableColumn } from '../../components/ui/DataTable';
import { EmptyState } from '../../components/ui/EmptyState';
import { controlClass } from '../../components/ui/fieldStyles';
import { Pager } from '../../components/ui/Pager';
import { formatDate, formatRelative } from '../../lib/format';
import { pageParam, useClampPage, useDebouncedSearchParam, useGoToPage } from '../../lib/listParams';
import { useMe } from '../auth/useMe';
import { errorText } from '../domain/shared';
import { StatusBadge, TwoFactorBadge } from './Badges';
import { InviteUserDialog } from './InviteUserDialog';
import { PendingInvitations } from './PendingInvitations';
import { roleSummary, userName, usersPath, type UserList, type UserRow } from './shared';

const PER_PAGE = 10;
/** A phone card's Open button: the card's full width, finger-sized (Button `sm` is 44 px tall on a phone). */
const CARD_OPEN = 'mt-1 w-full';
const panelClass = 'rounded-[10px] border border-line bg-white';

function UserName({ u, self }: { u: UserRow; self: boolean }) {
    const { t } = useTranslation('users');
    const name = userName(u);
    return (
        <div className="min-w-0">
            <Link to={usersPath(u.id)} className="font-semibold text-ink [overflow-wrap:anywhere] hover:underline">
                {name}
            </Link>
            {self && (
                <>
                    {' '}
                    <span className="ml-1 text-[11px] font-medium text-muted">{t('you')}</span>
                </>
            )}
            {name !== u.email && <div className="text-xs text-muted [overflow-wrap:anywhere]">{u.email}</div>}
        </div>
    );
}

function LastSignIn({ u }: { u: UserRow }) {
    const { t } = useTranslation('users');
    if (u.last_login_at === null) return <span className="text-muted">{t('never')}</span>;
    return <span title={formatDate(u.last_login_at)}>{formatRelative(u.last_login_at)}</span>;
}

function Badges({ u }: { u: UserRow }) {
    return (
        <div className="flex flex-wrap gap-1.5">
            <StatusBadge status={u.status} />
            <TwoFactorBadge on={u.two_factor_confirmed} />
        </div>
    );
}

/** `/users` (server admins): every console user, with search, pagination and "Invite user", then the pending invitations. */
export function UsersPage() {
    const { t } = useTranslation('users');
    const [params, setParams] = useSearchParams();
    const navigate = useNavigate();
    const q = params.get('q') ?? '';
    const page = pageParam(params);
    const [search, setSearch] = useDebouncedSearchParam();
    const goToPage = useGoToPage();
    const [inviting, setInviting] = useState(false);
    const myId = useMe().data?.id;

    const list = useQuery({
        queryKey: queryKeys.users({ q, page }),
        queryFn: () => apiFetch<UserList>('/users', { query: { q: q || undefined, page, per_page: PER_PAGE } }),
        placeholderData: (previous) => previous,
    });

    useClampPage(page, list.data?.meta.last_page, !list.isPlaceholderData);

    function clearSearch() {
        setSearch('');
        setParams({}, { replace: true });
    }

    const openButton = (u: UserRow, className?: string) => (
        <Button size="sm" className={className} onClick={() => void navigate(usersPath(u.id))} aria-label={t('openNamed', { name: userName(u) })}>
            {t('open')}
        </Button>
    );

    const columns: DataTableColumn<UserRow>[] = [
        { key: 'user', header: t('columns.user'), cell: (u) => <UserName u={u} self={u.id === myId} /> },
        { key: 'role', header: t('columns.role'), cell: (u) => roleSummary(u) },
        { key: 'lastSignIn', header: t('columns.lastSignIn'), cell: (u) => <LastSignIn u={u} /> },
        { key: 'status', header: t('columns.status'), cell: (u) => <Badges u={u} /> },
        {
            key: 'actions',
            header: <span className="sr-only">{t('columns.actions')}</span>,
            width: '90px',
            align: 'right',
            cell: (u) => openButton(u),
        },
    ];

    const card = (u: UserRow) => (
        <div className={cx(panelClass, 'flex flex-col gap-1.5 px-3.5 py-3')}>
            <UserName u={u} self={u.id === myId} />
            <div className="text-xs text-body">{roleSummary(u)}</div>
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5 text-xs text-muted">
                <Badges u={u} />
                <span>{t('lastSignInLine', { when: u.last_login_at === null ? t('never') : formatRelative(u.last_login_at) })}</span>
            </div>
            {/* The name link alone is too small a target for a finger: the card gets a full-width Open. */}
            {openButton(u, CARD_OPEN)}
        </div>
    );

    const empty =
        q === '' ? (
            <EmptyState title={t('empty.title')} />
        ) : (
            <EmptyState
                title={t('empty.noMatch', { q })}
                action={
                    <Button variant="link" onClick={clearSearch}>
                        {t('empty.clear')}
                    </Button>
                }
            />
        );

    const failed = list.isError && !list.data;

    return (
        <div className="flex flex-col gap-4">
            <div className={cx(panelClass, 'flex flex-col')}>
                <div className="flex flex-col gap-2.5 border-b border-line px-3.5 py-3 md:flex-row md:items-center md:px-[18px] md:py-3.5">
                    <input
                        type="search"
                        aria-label={t('search')}
                        placeholder={t('search')}
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        className={cx(controlClass, 'border-line-strong md:w-[260px]')}
                    />
                    <div className="md:ml-auto">
                        <Button variant="primary" size="lg" className="max-md:w-full" onClick={() => setInviting(true)}>
                            {t('invite')}
                        </Button>
                    </div>
                </div>
                {failed ? (
                    <div role="alert" className="flex items-center gap-3 px-4 py-6 text-[13px] text-danger">
                        <span className="min-w-0 flex-1">{errorText(list.error, t('loadFailed'))}</span>
                        <Button size="sm" onClick={() => void list.refetch()}>
                            {t('retry')}
                        </Button>
                    </div>
                ) : (
                    <div className="max-md:p-3">
                        <DataTable
                            rows={list.data?.data ?? []}
                            rowKey={(u) => String(u.id)}
                            columns={columns}
                            card={card}
                            loading={list.isPending}
                            empty={empty}
                        />
                    </div>
                )}
                {list.data && (
                    <Pager
                        className="px-3.5 py-3 md:px-[18px]"
                        page={list.data.meta.page}
                        perPage={list.data.meta.per_page}
                        total={list.data.meta.total}
                        onPage={goToPage}
                    />
                )}
                <InviteUserDialog open={inviting} onClose={() => setInviting(false)} />
            </div>
            <PendingInvitations />
        </div>
    );
}
