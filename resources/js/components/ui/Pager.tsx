import type { HTMLAttributes } from 'react';
import { useTranslation } from 'react-i18next';
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
    const { t } = useTranslation('common');
    const { last, label } = pageInfo(page, perPage, total);
    return (
        <nav
            aria-label={t('pager.label')}
            className={cx('flex items-center justify-between gap-3 text-[12.5px] text-muted', className)}
            {...rest}
        >
            <span>{label}</span>
            <span className="flex gap-2">
                <Button size="sm" aria-label={t('pager.previousPage')} disabled={page <= 1} onClick={() => onPage(page - 1)}>
                    {t('pager.previous')}
                </Button>
                <Button size="sm" aria-label={t('pager.nextPage')} disabled={page >= last} onClick={() => onPage(page + 1)}>
                    {t('pager.next')}
                </Button>
            </span>
        </nav>
    );
}
