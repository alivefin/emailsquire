import { useEffect, useRef, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { useIsPhone } from '../../lib/useIsPhone';
import { CardList } from './CardList';
import { rowActivation } from './rowActivation';
import { cx } from './cx';

export interface DataTableColumn<T> {
    key: string;
    header: ReactNode;
    cell: (r: T) => ReactNode;
    width?: string;
    align?: 'left' | 'right';
    /** Extra classes for the column's header and cells (`max-lg:hidden` to drop it on narrower screens). */
    className?: string;
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

export function DataTable<T>(props: DataTableProps<T>) {
    const isPhone = useIsPhone();
    if (isPhone && props.card) {
        return <CardList rows={props.rows} rowKey={props.rowKey} card={props.card} empty={props.empty} loading={props.loading} onRowClick={props.onRowClick} />;
    }
    return <Table {...props} />;
}

function Table<T>({ rows, rowKey, columns, selectable, empty, loading, onRowClick }: DataTableProps<T>) {
    const { t } = useTranslation('common');
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

    const colCount = columns.length + (selectable ? 1 : 0);
    const cellBase = 'px-[18px]';

    return (
        // `relative` keeps absolutely positioned content (sr-only headers) inside the scroll box, so a
        // table wider than its column scrolls here instead of widening the page.
        <div className="relative overflow-x-auto">
            <table className="w-full border-collapse text-left text-[13.5px]">
                <thead>
                    <tr className="border-b border-line bg-subtle text-[12.5px] font-semibold text-body">
                        {selectable && (
                            <th scope="col" className={cx(cellBase, 'w-10 py-2.5')}>
                                <input
                                    ref={headerRef}
                                    type="checkbox"
                                    aria-label={t('table.selectAll')}
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
                                className={cx(cellBase, 'py-2.5 font-semibold', c.align === 'right' ? 'text-right' : 'text-left', c.className)}
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
                                    {...(onRowClick ? rowActivation(() => onRowClick(r)) : {})}
                                    className={cx(
                                        'border-b border-line-soft',
                                        checked && 'bg-tint',
                                        onRowClick &&
                                            'cursor-pointer hover:bg-subtle focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-teal',
                                    )}
                                >
                                    {selectable && (
                                        <td className={cx(cellBase, 'w-10 py-2.5')}>
                                            <input
                                                type="checkbox"
                                                aria-label={t('table.selectRow', { key })}
                                                checked={checked}
                                                onChange={() => toggleOne(key)}
                                                className="size-4 accent-teal"
                                            />
                                        </td>
                                    )}
                                    {columns.map((c) => (
                                        <td
                                            key={c.key}
                                            className={cx(cellBase, 'py-[9px] text-body', c.align === 'right' ? 'text-right' : 'text-left', c.className)}
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
