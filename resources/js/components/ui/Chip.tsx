import type { HTMLAttributes, ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { cx } from './cx';

export type ChipKind = 'mailbox' | 'server' | 'external';

export interface ChipProps extends HTMLAttributes<HTMLSpanElement> {
    kind: ChipKind;
    onRemove?: () => void;
    children: ReactNode;
}

const kinds: Record<ChipKind, string> = {
    mailbox: 'bg-tint text-teal-dark border-teal/30',
    server: 'bg-subtle text-body border-line',
    external: 'bg-warn-bg text-warn border-warn-line',
};

export function Chip({ kind, onRemove, className, children, ...rest }: ChipProps) {
    const { t } = useTranslation('common');
    return (
        <span
            className={cx(
                'inline-flex max-w-full items-center gap-1 rounded-full border px-2.5 py-0.5 text-[12.5px] font-semibold',
                kinds[kind],
                className,
            )}
            {...rest}
        >
            <span className="truncate">{children}</span>
            {onRemove && (
                <button
                    type="button"
                    onClick={onRemove}
                    aria-label={typeof children === 'string' ? t('chip.removeNamed', { name: children }) : t('chip.remove')}
                    className="-mr-1 grid size-4 place-items-center rounded-full text-current hover:bg-black/10 focus-visible:outline-2 focus-visible:outline-teal"
                >
                    <span aria-hidden="true">×</span>
                </button>
            )}
        </span>
    );
}
