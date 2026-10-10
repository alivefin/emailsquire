import type { HTMLAttributes } from 'react';
import { formatBytes } from '../../lib/format';
import { storageLevel } from '../../lib/storage';
import type { StorageLevel } from '../../lib/storage';
import { cx } from './cx';

export interface StorageBarProps extends HTMLAttributes<HTMLDivElement> {
    used: number | null;
    limit: number;
    disabled: boolean;
}

const fills: Record<StorageLevel, string> = {
    off: 'bg-line-strong',
    ok: 'bg-teal',
    warn: 'bg-warn-bar',
    crit: 'bg-danger',
};

export function StorageBar({ used, limit, disabled, className, ...rest }: StorageBarProps) {
    const level = storageLevel(used, limit, disabled);
    const pct = used === null || limit <= 0 ? 0 : Math.min(100, (used / limit) * 100);
    const caption = used === null ? `of ${formatBytes(limit)}` : `${formatBytes(used)} of ${formatBytes(limit)}`;

    return (
        <div className={cx('min-w-[120px]', className)} {...rest}>
            <div
                role="progressbar"
                aria-label="Storage used"
                aria-valuemin={0}
                aria-valuemax={100}
                aria-valuenow={used === null ? undefined : Math.round(pct)}
                data-level={level}
                className="h-1.5 overflow-hidden rounded-full bg-line-soft"
            >
                <div className={cx('h-full rounded-full', fills[level])} style={{ width: `${pct}%` }} />
            </div>
            <div className={cx('mt-1 text-xs', level === 'off' ? 'text-faint' : 'text-muted')}>{caption}</div>
        </div>
    );
}
