import type { ReactNode } from 'react';
import { Link } from 'react-router';

export const statCardClass = 'rounded-[10px] border border-line bg-white px-4 py-3.5 text-left md:px-[18px] md:py-4';

/** A labelled figure with an optional note; a link when `to` is given. */
export function StatCard({ label, value, note, to }: { label: string; value: ReactNode; note?: ReactNode; to?: string }) {
    const body = (
        <>
            <div className="text-xs font-semibold text-muted md:text-[13px]">{label}</div>
            <div className="mt-0.5 text-2xl font-bold tracking-tight md:mt-1 md:text-[28px]">{value}</div>
            {note && <div className="text-xs text-muted">{note}</div>}
        </>
    );
    return to ? (
        <Link to={to} className={`${statCardClass} block hover:bg-subtle`}>
            {body}
        </Link>
    ) : (
        <div className={statCardClass}>{body}</div>
    );
}
