import { formatDate, formatRelative } from '../../lib/format';
import { activityText, actorLabel, type ActivityEntry, type ActorEntry } from '../dashboard/activityText';

export type ActivityItem = ActivityEntry & ActorEntry & { id: number; occurred_at: string };

/** The relative time, with the full date and time on hover. */
export function ActivityTime({ at, className }: { at: string; className?: string }) {
    return (
        <time dateTime={at} title={formatDate(at)} className={className}>
            {formatRelative(at)}
        </time>
    );
}

/** A compact activity list (the dashboard's recent activity, a domain's latest rows): time, then who did what. */
export function ActivityList({ items }: { items: ActivityItem[] }) {
    return (
        <ul>
            {items.map((a) => (
                <li
                    key={a.id}
                    className="flex flex-col gap-0.5 border-b border-line-soft px-3.5 py-2.5 text-[13px] text-body md:flex-row md:gap-3 md:px-[18px]"
                >
                    <ActivityTime at={a.occurred_at} className="flex-none text-[11.5px] text-muted md:w-[84px] md:text-[13px]" />
                    <span className="min-w-0 [overflow-wrap:anywhere]">
                        <b className="font-semibold text-ink">{actorLabel(a)}</b> {activityText(a)}
                    </span>
                </li>
            ))}
        </ul>
    );
}
