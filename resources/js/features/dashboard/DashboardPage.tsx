import { useState, type ReactNode } from 'react';
import { Link } from 'react-router';
import { ApiProblem, problemMessage } from '../../api/problems';
import { Button } from '../../components/ui/Button';
import { EmptyState } from '../../components/ui/EmptyState';
import { StorageBar } from '../../components/ui/StorageBar';
import { formatBytes, formatRelative } from '../../lib/format';
import { useDashboard } from '../../layout/useNavData';
import { EditMailboxDrawer } from '../domain/EditMailboxDrawer';
import { ForwardingDrawer } from '../domain/ForwardingDrawer';
import { NewMailboxDrawer } from '../domain/NewMailboxDrawer';
import { activityText } from './activityText';

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
    return (
        <div aria-busy="true" aria-label="Loading dashboard" className="grid grid-cols-2 gap-2.5 md:grid-cols-4 md:gap-3.5">
            {[0, 1, 2, 3].map((i) => (
                <div key={i} className={`${cardClass} h-[88px] animate-pulse bg-subtle`} />
            ))}
        </div>
    );
}

export function DashboardPage() {
    const { data, error, isPending, refetch } = useDashboard();
    const [adding, setAdding] = useState(false);
    const [forwarding, setForwarding] = useState(false);
    const [editing, setEditing] = useState<{ domain: string; local: string } | null>(null);

    if (isPending && !error) return <Skeleton />;
    if (!data) {
        return (
            <div role="alert" className="flex items-center gap-3 rounded-[10px] border border-danger-line bg-danger-bg px-4 py-3 text-[13px] text-danger">
                <span className="min-w-0 flex-1">
                    {error instanceof ApiProblem ? problemMessage(error) : 'The dashboard could not be loaded.'}
                </span>
                <Button size="sm" onClick={() => void refetch()}>
                    Retry
                </Button>
            </div>
        );
    }

    const { counts, near_full: nearFull, recent_activity: activity, server_storage: storage } = data;

    return (
        <div className="flex flex-col gap-3.5">
            <div className="grid grid-cols-2 gap-2.5 md:grid-cols-4 md:gap-3.5">
                <StatCard
                    label="Domains"
                    value={count(counts.domains)}
                    note={
                        counts.domains_added_this_month > 0 ? (
                            <span className="font-semibold text-teal-dark">+{counts.domains_added_this_month} this month</span>
                        ) : undefined
                    }
                    to="/domains"
                />
                <StatCard label="Mailboxes" value={count(counts.mailboxes)} note="across all domains" to="/addresses" />
                <StatCard
                    label="Forwarding"
                    value={count(counts.forwarding)}
                    note={`${count(counts.forwarding_local)} local · ${count(counts.forwarding_external)} external`}
                    to="/addresses"
                />
                <StatCard
                    label="Server storage"
                    value={storage ? formatBytes(storage.used_bytes) : 'Not checked yet'}
                    note={storage ? `${formatBytes(storage.used_bytes)} of ${formatBytes(storage.total_bytes)}` : undefined}
                />
            </div>

            <div className="grid gap-3.5 md:grid-cols-[1.2fr_1fr]">
                <section className={`${panelClass} flex flex-col`} aria-labelledby="near-full-title">
                    <div className="flex items-center border-b border-line px-3.5 py-3 md:px-[18px] md:py-3.5">
                        <h2 id="near-full-title" className="text-sm font-bold md:text-[15px]">
                            Mailboxes close to their limit
                        </h2>
                        <span className="ml-auto text-[12.5px] text-muted max-md:hidden">Click to change the limit</span>
                    </div>
                    {nearFull.items.length === 0 ? (
                        <EmptyState title="No mailboxes are close to their limit" />
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
                    <div className="flex gap-2 px-3.5 py-3 md:px-[18px]">
                        <Button variant="primary" onClick={() => setAdding(true)}>
                            Add mailbox
                        </Button>
                        <Button onClick={() => setForwarding(true)}>Add forwarding</Button>
                    </div>
                </section>

                <section className={panelClass} aria-labelledby="activity-title">
                    <h2 id="activity-title" className="border-b border-line px-3.5 py-3 text-sm font-bold md:px-[18px] md:py-3.5 md:text-[15px]">
                        Recent activity
                    </h2>
                    {activity.length === 0 ? (
                        <EmptyState title="No activity yet" />
                    ) : (
                        <ul>
                            {activity.map((a, i) => (
                                <li
                                    key={`${a.occurred_at}-${i}`}
                                    className="flex flex-col gap-0.5 border-b border-line-soft px-3.5 py-2.5 text-[13px] text-body md:flex-row md:gap-3 md:px-[18px]"
                                >
                                    <span className="flex-none text-[11.5px] text-muted md:w-[84px] md:text-[13px]">
                                        {formatRelative(a.occurred_at)}
                                    </span>
                                    <span className="min-w-0">
                                        <b className="font-semibold text-ink">{a.actor_name}</b> {activityText(a)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>

            <p className="px-0.5 text-xs text-muted">
                {data.usage_checked_at ? `Usage checked ${formatRelative(data.usage_checked_at)}` : 'Usage not checked yet'}
            </p>

            <NewMailboxDrawer open={adding} onClose={() => setAdding(false)} />
            <ForwardingDrawer open={forwarding} onClose={() => setForwarding(false)} />
            {editing && <EditMailboxDrawer open onClose={() => setEditing(null)} domain={editing.domain} local={editing.local} />}
        </div>
    );
}
