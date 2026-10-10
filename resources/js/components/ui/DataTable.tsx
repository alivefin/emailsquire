import { useEffect, useRef, type MouseEvent, type ReactNode } from 'react';
import { useIsPhone } from '../../lib/useIsPhone';
import { CardList } from './CardList';
import { cx } from './cx';

export interface DataTableColumn<T> {
    key: string;
    header: ReactNode;
    cell: (r: T) => ReactNode;
    width?: string;
    align?: 'left' | 'right';
}

export interface DataTableProps<T> {
    rows: T[];
    rowKey: (r: T) => string;
    columns: DataTableColumn<T>[];
    selectable?: { selected: Set<string>; onChange(next: Set<string>): void };
    empty?: ReactNode;
    loading?: boolean;
    onRowClick?: (r: T) => void;
    /** Phone card renderer; required wherever the table may run below 768 px. */
    card?: (r: T) => ReactNode;
}

const INTERACTIVE = 'button, a, input, select, textarea, label, [role="menu"], [role="menuitem"], [role="button"]';

export function DataTable<T>(props: DataTableProps<T>) {
    const isPhone = useIsPhone();
    if (isPhone && props.card) {
        return <CardList rows={props.rows} rowKey={props.rowKey} card={props.card} empty={props.empty} loading={props.loading} />;
    }
    return <Table {...props} />;
}

function Table<T>({ rows, rowKey, columns, selectable, empty, loading, onRowClick }: DataTableProps<T>) {
    const headerRef = useRef<HTMLInputElement>(null);
    const pageKeys = rows.map(rowKey);
    const selectedOnPage = selectable ? pageKeys.filter((k) => selectable.selected.has(k)).length : 0;
    const allSelected = pageKeys.length > 0 && selectedOnPage === pageKeys.length;
    const some = selectedOnPage > 0 && !allSelected;

    useEffect(() => {
        if (headerRef.current) headerRef.current.indeterminate = some;
    }, [some]);

    const toggleAll = () => {
        if (!selectable) return;
        const next = new Set(selectable.selected);
        for (const k of pageKeys) {
            if (allSelected) next.delete(k);
            else next.add(k);
        }
        selectable.onChange(next);
    };

    const toggleOne = (key: string) => {
        if (!selectable) return;
        const next = new Set(selectable.selected);
        if (next.has(key)) next.delete(key);
        else next.add(key);
        selectable.onChange(next);
    };

    const handleRowClick = (e: MouseEvent<HTMLTableRowElement>, r: T) => {
        if (!onRowClick) return;
        const target = e.target as Element;
        const hit = target.closest(INTERACTIVE);
        if (hit && e.currentTarget.contains(hit)) return;
        onRowClick(r);
    };

    const colCount = columns.length + (selectable ? 1 : 0);
    const cellBase = 'px-[18px]';

    return (
        <div className="overflow-x-auto">
            <table className="w-full border-collapse text-left text-[13.5px]">
                <thead>
                    <tr className="border-b border-line bg-subtle text-[12.5px] font-semibold text-body">
                        {selectable && (
                            <th scope="col" className={cx(cellBase, 'w-10 py-2.5')}>
                                <input
                                    ref={headerRef}
                                    type="checkbox"
                                    aria-label="Select all on this page"
                                    checked={allSelected}
                                    disabled={pageKeys.length === 0}
                                    onChange={toggleAll}
                                    className="size-4 accent-teal"
                                />
                            </th>
                        )}
                        {columns.map((c) => (
                            <th
                                key={c.key}
                                scope="col"
                                style={c.width ? { width: c.width } : undefined}
                                className={cx(cellBase, 'py-2.5 font-semibold', c.align === 'right' ? 'text-right' : 'text-left')}
                            >
                                {c.header}
                            </th>
                        ))}
                    </tr>
                </thead>
                {loading ? (
                    <tbody data-testid="table-skeleton">
                        {[0, 1, 2].map((i) => (
                            <tr key={i} aria-hidden="true" className="border-b border-line-soft">
                                <td colSpan={colCount} className={cx(cellBase, 'py-3')}>
                                    <div className="h-4 animate-pulse rounded bg-line-soft" />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                ) : rows.length === 0 ? (
                    empty ? (
                        <tbody>
                            <tr>
                                <td colSpan={colCount}>{empty}</td>
                            </tr>
                        </tbody>
                    ) : null
                ) : (
                    <tbody>
                        {rows.map((r) => {
                            const key = rowKey(r);
                            const checked = selectable?.selected.has(key) ?? false;
                            return (
                                <tr
                                    key={key}
                                    onClick={onRowClick ? (e) => handleRowClick(e, r) : undefined}
                                    className={cx(
                                        'border-b border-line-soft',
                                        checked && 'bg-tint',
                                        onRowClick && 'cursor-pointer hover:bg-subtle',
                                    )}
                                >
                                    {selectable && (
                                        <td className={cx(cellBase, 'w-10 py-2.5')}>
                                            <input
                                                type="checkbox"
                                                aria-label={`Select ${key}`}
                                                checked={checked}
                                                onChange={() => toggleOne(key)}
                                                className="size-4 accent-teal"
                                            />
                                        </td>
                                    )}
                                    {columns.map((c) => (
                                        <td
                                            key={c.key}
                                            className={cx(cellBase, 'py-[9px] text-body', c.align === 'right' ? 'text-right' : 'text-left')}
                                        >
                                            {c.cell(r)}
                                        </td>
                                    ))}
                                </tr>
                            );
                        })}
                    </tbody>
                )}
            </table>
        </div>
    );
}
