import type { ReactNode } from 'react';

export interface CardListProps<T> {
    rows: T[];
    rowKey: (r: T) => string;
    card: (r: T) => ReactNode;
    empty?: ReactNode;
    loading?: boolean;
}

/** Phone variant of DataTable: one card per row. Cards bring their own surface styling. */
export function CardList<T>({ rows, rowKey, card, empty, loading }: CardListProps<T>) {
    if (loading) {
        return (
            <div data-testid="card-skeleton" className="flex flex-col gap-2" aria-busy="true">
                {[0, 1, 2].map((i) => (
                    <div key={i} className="h-20 animate-pulse rounded-[10px] border border-line bg-line-soft" />
                ))}
            </div>
        );
    }
    if (rows.length === 0) {
        return empty ? <div className="rounded-[10px] border border-line bg-white">{empty}</div> : null;
    }
    return (
        <div className="flex flex-col gap-2">
            {rows.map((r) => (
                <div key={rowKey(r)}>{card(r)}</div>
            ))}
        </div>
    );
}
