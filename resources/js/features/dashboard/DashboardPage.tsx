import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { ApiProblem, problemMessage } from '../../api/problems';
import { Button } from '../../components/ui/Button';
import { EmptyState } from '../../components/ui/EmptyState';
import { StorageBar } from '../../components/ui/StorageBar';
import { cx } from '../../components/ui/cx';
import { formatBytes, formatRelative } from '../../lib/format';
import { canWrite, isServerAdmin } from '../../lib/roles';
import { useDashboard, useDomainNames } from '../../layout/useNavData';
import { useMe } from '../auth/useMe';
import { EditMailboxDrawer } from '../domain/EditMailboxDrawer';
import { ForwardingDrawer } from '../domain/ForwardingDrawer';
import { NewMailboxDrawer } from '../domain/NewMailboxDrawer';
import { ActivityList } from '../activity/ActivityList';

const cardClass = 'rounded-[10px] border border-line bg-white px-4 py-3.5 text-left md:px-[18px] md:py-4';
const panelClass = 'rounded-[10px] border border-line bg-white';

function count(n: number): string {
    return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
}

function StatCard({ label, value, note, to }: { label: string; value: ReactNode; note?: ReactNode; to?: string }) {
    const body = (
        <>
            <div className="text-xs font-semibold text-muted md:text-[13px]">{label}</div>
            <div className="mt-0.5 text-2xl font-bold tracking-tight md:mt-1 md:text-[28px]">{value}</div>
            {note && <div className="text-xs text-muted">{note}</div>}
        </>
    );
    return to ? (
        <Link to={to} className={`${cardClass} block hover:bg-subtle`}>
            {body}
        </Link>
    ) : (
        <div className={cardClass}>{body}</div>
    );
}

function Skeleton() {
    const { t } = useTranslation('dashboard');
    return (
        <div aria-busy="true" aria-label={t('loading')} className="grid grid-cols-2 gap-2.5 md:gap-3.5 lg:grid-cols-4">
            {[0, 1, 2, 3].map((i) => (
                <div key={i} className={`${cardClass} h-[88px] animate-pulse bg-subtle`} />
            ))}
        </div>
    );
}

export function DashboardPage() {
    const { t } = useTranslation('dashboard');
    const { data, error, isPending, refetch } = useDashboard();
    // Server storage is for server admins (the server sends it to no one else). Recent activity shows to
    // everyone: the server sends only the rows the user may read. The add buttons need a domain the user
    // may write to.
    const admin = isServerAdmin(useMe().data);
    const writable = (useDomainNames().data ?? []).some((d) => canWrite(d.role));
    const [adding, setAdding] = useState(false);
    const [forwarding, setForwarding] = useState(false);
    const [editing, setEditing] = useState<{ domain: string; local: string } | null>(null);

    if (isPending && !error) return <Skeleton />;
    if (!data) {
        return (
            <div role="alert" className="flex items-center gap-3 rounded-[10px] border border-danger-line bg-danger-bg px-4 py-3 text-[13px] text-danger">
                <span className="min-w-0 flex-1">
                    {error instanceof ApiProblem ? problemMessage(error) : t('loadFailed')}
                </span>
                <Button size="sm" onClick={() => void refetch()}>
                    {t('retry')}
                </Button>
            </div>
        );
    }

    const { counts, near_full: nearFull, recent_activity: activity, server_storage: storage } = data;

    return (
        <div className="flex flex-col gap-3.5">
            {/* Four across only from 1024 px: beside the sidebar the longer Finnish labels need two. */}
            <div className={cx('grid gap-2.5 md:gap-3.5', admin ? 'grid-cols-2 lg:grid-cols-4' : 'grid-cols-2 lg:grid-cols-3')}>
                <StatCard
                    label={t('stats.domains')}
                    value={count(counts.domains)}
                    note={
                        counts.domains_added_this_month > 0 ? (
                            <span className="font-semibold text-teal-dark">{t('stats.addedThisMonth', { added: counts.domains_added_this_month })}</span>
                        ) : undefined
                    }
                    to="/domains"
                />
                <StatCard label={t('stats.mailboxes')} value={count(counts.mailboxes)} note={t('stats.acrossDomains')} to="/addresses" />
                <StatCard
                    label={t('stats.forwarding')}
                    value={count(counts.forwarding)}
                    note={t('stats.forwardingSplit', {
                        local: t('stats.local', { count: counts.forwarding_local, n: count(counts.forwarding_local) }),
                        external: t('stats.external', { count: counts.forwarding_external, n: count(counts.forwarding_external) }),
                    })}
                    to="/addresses"
                />
                {admin && (
                    <StatCard
                        label={t('stats.storage')}
                        value={storage ? formatBytes(storage.used_bytes) : t('stats.notChecked')}
                        note={storage ? t('stats.storageOf', { used: formatBytes(storage.used_bytes), total: formatBytes(storage.total_bytes) }) : undefined}
                    />
                )}
            </div>

            <div className="grid gap-3.5 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)]">
                <section className={`${panelClass} flex flex-col`} aria-labelledby="near-full-title">
                    <div className="flex items-center border-b border-line px-3.5 py-3 md:px-[18px] md:py-3.5">
                        <h2 id="near-full-title" className="text-sm font-bold md:text-[15px]">
                            {t('nearFull.title')}
                        </h2>
                        {writable && <span className="ml-auto text-[12.5px] text-muted max-md:hidden">{t('nearFull.hint')}</span>}
                    </div>
                    {nearFull.items.length === 0 ? (
                        <EmptyState title={t('nearFull.empty')} />
                    ) : (
                        nearFull.items.map((m) => (
                            <button
                                key={m.address}
                                type="button"
                                onClick={() => setEditing({ domain: m.domain, local: m.local })}
                                className="grid gap-1 border-b border-line-soft px-3.5 py-2.5 text-left text-[13.5px] hover:bg-subtle md:grid-cols-[minmax(0,1fr)_200px] md:items-center md:gap-4 md:px-[18px]"
                            >
                                <span className="min-w-0">
                                    <span className="block [overflow-wrap:anywhere]">{m.address}</span>
                                    {m.display_name && <span className="block text-xs text-muted">{m.display_name}</span>}
                                </span>
                                <StorageBar used={m.used_bytes} limit={m.quota_bytes} disabled={m.status === 'disabled'} />
                            </button>
                        ))
                    )}
                    {writable && (
                        <div className="flex flex-wrap gap-2 px-3.5 py-3 md:px-[18px]">
                            <Button variant="primary" onClick={() => setAdding(true)}>
                                {t('addMailbox')}
                            </Button>
                            <Button onClick={() => setForwarding(true)}>{t('addForwarding')}</Button>
                        </div>
                    )}
                </section>

                <section className={`${panelClass} flex flex-col`} aria-labelledby="activity-title">
                    <h2 id="activity-title" className="border-b border-line px-3.5 py-3 text-sm font-bold md:px-[18px] md:py-3.5 md:text-[15px]">
                        {t('activity.title')}
                    </h2>
                    {activity.length === 0 ? <EmptyState title={t('activity.empty')} /> : <ActivityList items={activity} />}
                    <div className="px-3.5 py-3 md:px-[18px]">
                        <Link to="/activity" className="text-[12.5px] font-semibold text-teal-dark hover:underline">
                            {t('activity.openLog')}
                        </Link>
                    </div>
                </section>
            </div>

            <p className="px-0.5 text-xs text-muted">
                {data.usage_checked_at ? t('usage.checked', { when: formatRelative(data.usage_checked_at) }) : t('usage.notChecked')}
            </p>

            <NewMailboxDrawer open={adding} onClose={() => setAdding(false)} />
            <ForwardingDrawer open={forwarding} onClose={() => setForwarding(false)} />
            {editing && <EditMailboxDrawer open onClose={() => setEditing(null)} domain={editing.domain} local={editing.local} />}
        </div>
    );
}
