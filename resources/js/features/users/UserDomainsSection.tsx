import { useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { apiFetch } from '../../api/client';
import { Button } from '../../components/ui/Button';
import { ConfirmDialog } from '../../components/ui/ConfirmDialog';
import { DataTable, type DataTableColumn } from '../../components/ui/DataTable';
import { Select } from '../../components/ui/Select';
import { formatDay } from '../../lib/format';
import { useDomainNames } from '../../layout/useNavData';
import type { GrantRole } from '../access/shared';
import { ProfileSection } from '../profile/ProfileSection';
import { userName, usersPath, type UserDetail, type UserGrant } from './shared';
import { useUserWrite } from './userWrite';

const selectClass =
    'h-8 w-full max-w-[150px] rounded-md border border-line-strong bg-white px-2 text-[13px] text-ink outline-none ' +
    'focus:border-teal focus:ring-2 focus:ring-teal/20 max-md:h-11 max-md:max-w-none max-md:text-base';

/** What a domain's confirmation is asking about. */
type GrantConfirm = { kind: 'role'; grant: UserGrant; role: GrantRole } | { kind: 'remove'; grant: UserGrant } | null;

export function DomainsCard({ user }: { user: UserDetail }) {
    const { t } = useTranslation(['users', 'access']);
    const [confirm, setConfirm] = useState<GrantConfirm>(null);
    const name = userName(user);

    // A grant write never touches `me`: a server admin, the only one here, has no grants.
    const put = useUserWrite(
        user,
        false,
        (json: { domain: string; role: GrantRole }) => apiFetch<unknown>(usersPath(user.id, 'grants'), { method: 'POST', json }),
        t('access:roleDialog.failed'),
    );
    const remove = useUserWrite(
        user,
        false,
        (domain: string) => apiFetch<undefined>(usersPath(user.id, 'grants', domain), { method: 'DELETE' }),
        t('access:removeDialog.failed'),
    );

    if (user.is_server_admin) {
        return (
            <ProfileSection title={t('detail.domains.title')}>
                <p className="text-[13.5px] text-body">{t('detail.domains.adminNote')}</p>
            </ProfileSection>
        );
    }

    const added = (g: UserGrant) => {
        if (g.granted_at === null) return g.granted_by_name ?? '—';
        return g.granted_by_name === null
            ? formatDay(g.granted_at)
            : t('access:addedBy', { name: g.granted_by_name, date: formatDay(g.granted_at) });
    };

    const roleSelect = (g: UserGrant) => (
        <select
            aria-label={t('detail.domains.roleOn', { domain: g.domain_unicode })}
            value={g.role}
            onChange={(e) => {
                put.clear();
                setConfirm({ kind: 'role', grant: g, role: e.target.value === 'manager' ? 'manager' : 'viewer' });
            }}
            className={selectClass}
        >
            <option value="manager">{t('access:roles.manager')}</option>
            <option value="viewer">{t('access:roles.viewer')}</option>
        </select>
    );

    const removeButton = (g: UserGrant) => (
        <Button
            size="sm"
            variant="danger"
            aria-label={t('detail.domains.removeNamed', { domain: g.domain_unicode })}
            onClick={() => {
                remove.clear();
                setConfirm({ kind: 'remove', grant: g });
            }}
        >
            {t('detail.domains.remove')}
        </Button>
    );

    const domainLink = (g: UserGrant) => (
        <Link
            to={`/domains/${encodeURIComponent(g.domain)}/mailboxes`}
            className="font-semibold text-ink [overflow-wrap:anywhere] hover:underline"
        >
            {g.domain_unicode}
        </Link>
    );

    const columns: DataTableColumn<UserGrant>[] = [
        { key: 'domain', header: t('detail.domains.columns.domain'), cell: domainLink },
        { key: 'role', header: t('detail.domains.columns.role'), width: '160px', cell: roleSelect },
        { key: 'added', header: t('detail.domains.columns.added'), cell: (g) => <span className="text-muted">{added(g)}</span> },
        {
            key: 'actions',
            header: <span className="sr-only">{t('detail.domains.columns.actions')}</span>,
            align: 'right',
            cell: removeButton,
        },
    ];

    const card = (g: UserGrant) => (
        <div className="flex flex-col gap-2 rounded-[10px] border border-line bg-white px-3.5 py-3">
            <div>
                {domainLink(g)}
                <div className="text-xs text-muted">{added(g)}</div>
            </div>
            <div className="flex items-center gap-2">
                <div className="min-w-0 flex-1">{roleSelect(g)}</div>
                {removeButton(g)}
            </div>
        </div>
    );

    return (
        <ProfileSection title={t('detail.domains.title')}>
            {user.grants.length === 0 ? (
                <p className="text-[13.5px] text-muted">{t('detail.domains.none')}</p>
            ) : (
                <div className="-mx-5 border-y border-line max-md:mx-0 max-md:border-0">
                    <DataTable rows={user.grants} rowKey={(g) => g.domain} columns={columns} card={card} />
                </div>
            )}
            <AddDomainForm user={user} />
            {confirm?.kind === 'role' && (
                <ConfirmDialog
                    tone="primary"
                    title={t(confirm.role === 'manager' ? 'detail.domains.toManager' : 'detail.domains.toViewer', {
                        name,
                        domain: confirm.grant.domain_unicode,
                    })}
                    confirm={t('access:roleDialog.confirm')}
                    cancel={t('cancel')}
                    busy={put.busy}
                    error={put.error}
                    onClose={() => setConfirm(null)}
                    onConfirm={() =>
                        put.send(
                            { domain: confirm.grant.domain, role: confirm.role },
                            t('detail.domains.changed', { domain: confirm.grant.domain_unicode }),
                            () => setConfirm(null),
                        )
                    }
                >
                    {t(confirm.role === 'manager' ? 'access:roleDialog.managerBody' : 'access:roleDialog.viewerBody', {
                        name,
                        domain: confirm.grant.domain_unicode,
                    })}
                </ConfirmDialog>
            )}
            {confirm?.kind === 'remove' && (
                <ConfirmDialog
                    tone="danger"
                    title={t('access:removeDialog.title', { name })}
                    confirm={t('access:removeDialog.confirm')}
                    cancel={t('cancel')}
                    busy={remove.busy}
                    error={remove.error}
                    onClose={() => setConfirm(null)}
                    onConfirm={() =>
                        remove.send(
                            confirm.grant.domain,
                            t('detail.domains.removed', { domain: confirm.grant.domain_unicode }),
                            () => setConfirm(null),
                        )
                    }
                >
                    {t('access:removeDialog.body', { name, domain: confirm.grant.domain_unicode })}
                </ConfirmDialog>
            )}
        </ProfileSection>
    );
}

/** "Add domain": the domains they don't have yet, with a role. */
function AddDomainForm({ user }: { user: UserDetail }) {
    const { t } = useTranslation(['users', 'access']);
    const names = useDomainNames();
    const [domain, setDomain] = useState('');
    const [role, setRole] = useState<GrantRole>('viewer');
    const add = useUserWrite(
        user,
        false,
        (json: { domain: string; role: GrantRole }) => apiFetch<unknown>(usersPath(user.id, 'grants'), { method: 'POST', json }),
        t('detail.domains.addFailed'),
    );

    const have = new Set(user.grants.map((g) => g.domain));
    const available = (names.data ?? []).filter((d) => !have.has(d.name));
    const chosen = available.find((d) => d.name === domain);

    if (names.data && available.length === 0) {
        return <p className="mt-4 text-[13px] text-muted">{t('detail.domains.allAdded')}</p>;
    }

    function submit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        if (!chosen) return;
        add.send({ domain: chosen.name, role }, t('detail.domains.added', { domain: chosen.unicode_name }), () => setDomain(''));
    }

    return (
        <form noValidate onSubmit={submit} className="mt-4">
            <div className="mb-2 text-[13px] font-bold text-ink">{t('detail.domains.add')}</div>
            <div className="flex flex-col gap-2.5 md:flex-row md:items-end">
                <Select
                    label={t('detail.domains.domain')}
                    className="md:flex-1"
                    value={domain}
                    onChange={(e) => {
                        setDomain(e.target.value);
                        add.clear();
                    }}
                >
                    <option value="">{t('detail.domains.domainPlaceholder')}</option>
                    {available.map((d) => (
                        <option key={d.name} value={d.name}>
                            {d.unicode_name}
                        </option>
                    ))}
                </Select>
                <Select
                    label={t('detail.domains.role')}
                    className="md:w-[160px]"
                    value={role}
                    onChange={(e) => setRole(e.target.value === 'manager' ? 'manager' : 'viewer')}
                >
                    <option value="manager">{t('access:roles.manager')}</option>
                    <option value="viewer">{t('access:roles.viewer')}</option>
                </Select>
                <Button type="submit" size="lg" disabled={!chosen} busy={add.busy}>
                    {t('detail.domains.addButton')}
                </Button>
            </div>
            {add.error && (
                <p role="alert" className="mt-1.5 text-[12.5px] text-danger">
                    {add.error}
                </p>
            )}
        </form>
    );
}
