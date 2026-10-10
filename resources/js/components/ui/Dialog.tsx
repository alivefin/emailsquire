import { useId, useRef } from 'react';
import type { ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { useIsPhone } from '../../lib/useIsPhone';
import { useModal } from '../../lib/useModal';
import { cx } from './cx';

export interface DialogProps {
    open: boolean;
    onClose: () => void;
    title: string;
    subtitle?: string;
    children: ReactNode;
    footer?: ReactNode;
    size?: 'sm' | 'md';
}

/** Centred card on desktop, bottom sheet on phone. Children are unmounted when closed. */
export function Dialog({ open, onClose, title, subtitle, children, footer, size = 'md' }: DialogProps) {
    const phone = useIsPhone();
    const panel = useRef<HTMLDivElement>(null);
    const body = useRef<HTMLDivElement>(null);
    const titleId = useId();
    useModal(open, onClose, panel, body);

    if (!open) return null;

    return createPortal(
        <div className={cx('fixed inset-0 z-50 flex justify-center', phone ? 'items-end' : 'items-center p-4')}>
            <div data-testid="overlay-backdrop" onClick={onClose} className="absolute inset-0 bg-ink/35" />
            <div
                ref={panel}
                role="dialog"
                aria-modal="true"
                aria-labelledby={titleId}
                tabIndex={-1}
                className={cx(
                    'relative flex max-h-full w-full flex-col bg-white shadow-[0_24px_60px_rgba(27,29,33,0.25)] outline-none',
                    phone
                        ? 'rounded-t-2xl'
                        : cx('rounded-2xl', size === 'sm' ? 'max-w-[400px]' : 'max-w-[520px]'),
                )}
            >
                <div className="px-[22px] pt-5">
                    <div id={titleId} className="text-[17px] font-bold text-ink">
                        {title}
                    </div>
                    {subtitle && <div className="mt-0.5 text-[12.5px] wrap-anywhere text-muted">{subtitle}</div>}
                </div>
                <div ref={body} className="overflow-auto px-[22px] py-4 text-[13.5px] text-body">
                    {children}
                </div>
                {footer && <div className="flex justify-end gap-2 px-[22px] pb-5">{footer}</div>}
            </div>
        </div>,
        document.body,
    );
}
