import type { HTMLAttributes } from 'react';
import { pageInfo } from '../../lib/pager';
import { Button } from './Button';
import { cx } from './cx';

export interface PagerProps extends Omit<HTMLAttributes<HTMLElement>, 'onChange'> {
    page: number;
    perPage: number;
    total: number;
    onPage: (page: number) => void;
}

export function Pager({ page, perPage, total, onPage, className, ...rest }: PagerProps) {
    const { last, label } = pageInfo(page, perPage, total);
    return (
        <nav
            aria-label="Pagination"
            className={cx('flex items-center justify-between gap-3 text-[12.5px] text-muted', className)}
            {...rest}
        >
            <span>{label}</span>
            <span className="flex gap-2">
                <Button size="sm" aria-label="Previous page" disabled={page <= 1} onClick={() => onPage(page - 1)}>
                    Previous
                </Button>
                <Button size="sm" aria-label="Next page" disabled={page >= last} onClick={() => onPage(page + 1)}>
                    Next
                </Button>
            </span>
        </nav>
    );
}
