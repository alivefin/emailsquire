import { useId, useRef } from 'react';
import type { ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { useIsPhone } from '../../lib/useIsPhone';
import { useModal } from '../../lib/useModal';
import { cx } from './cx';

export interface DrawerProps {
    open: boolean;
    onClose: () => void;
    title: string;
    subtitle?: string;
    children: ReactNode;
    footer?: ReactNode;
    /** Disables the close button, for while a write that must not be abandoned is pending. */
    closeDisabled?: boolean;
}

/** 440 px right panel on desktop, full-screen sheet on phone. Children are unmounted when closed. */
export function Drawer({ open, onClose, title, subtitle, children, footer, closeDisabled = false }: DrawerProps) {
    const phone = useIsPhone();
    const panel = useRef<HTMLDivElement>(null);
    const body = useRef<HTMLDivElement>(null);
    const titleId = useId();
    useModal(open, onClose, panel, body);

    if (!open) return null;

    return createPortal(
        <div className="fixed inset-0 z-40">
            <div data-testid="overlay-backdrop" onClick={onClose} className="absolute inset-0 bg-ink/25" />
            <div
                ref={panel}
                role="dialog"
                aria-modal="true"
                aria-labelledby={titleId}
                tabIndex={-1}
                className={cx(
                    'absolute top-0 right-0 bottom-0 flex flex-col bg-white outline-none',
                    phone ? 'w-full' : 'w-[440px] max-w-full border-l border-line shadow-[-16px_0_40px_rgba(27,29,33,0.12)]',
                )}
            >
                <div className="flex items-center gap-2.5 border-b border-line px-[22px] py-[18px]">
                    <div className="min-w-0">
                        <div id={titleId} className="text-[17px] font-bold text-ink">
                            {title}
                        </div>
                        {subtitle && <div className="text-[12.5px] wrap-anywhere text-muted">{subtitle}</div>}
                    </div>
                    <button
                        type="button"
                        aria-label="Close"
                        onClick={onClose}
                        disabled={closeDisabled}
                        className="ml-auto flex h-9 w-9 flex-none items-center justify-center rounded-md text-muted hover:bg-subtle disabled:cursor-not-allowed disabled:opacity-40"
                    >
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden="true">
                            <path d="M3 3l10 10M13 3L3 13" />
                        </svg>
                    </button>
                </div>
                <div ref={body} className="flex-1 overflow-auto px-[22px] py-5">
                    {children}
                </div>
                {footer && <div className="flex gap-2 border-t border-line px-[22px] py-3.5">{footer}</div>}
            </div>
        </div>,
        document.body,
    );
}
