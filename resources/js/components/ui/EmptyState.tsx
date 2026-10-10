import type { HTMLAttributes, ReactNode } from 'react';
import { cx } from './cx';

export interface EmptyStateProps extends Omit<HTMLAttributes<HTMLDivElement>, 'title'> {
    title: string;
    action?: ReactNode;
}

export function EmptyState({ title, action, className, children, ...rest }: EmptyStateProps) {
    return (
        <div className={cx('flex flex-col items-center gap-2 px-6 py-12 text-center', className)} {...rest}>
            <h3 className="text-[15px] font-bold text-ink">{title}</h3>
            {children && <div className="max-w-sm text-[13px] text-muted">{children}</div>}
            {action && <div className="mt-2">{action}</div>}
        </div>
    );
}
