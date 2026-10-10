import { useEffect, useRef } from 'react';
import type { RefObject } from 'react';
import { useLocation } from 'react-router';

const FOCUSABLE =
    'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

// Open modals, oldest first. Only the topmost handles keys.
const stack: object[] = [];

// Scroll lock shared by all open modals: the original value is saved on 0 -> 1
// and restored on 1 -> 0, whatever the closing order.
let lockCount = 0;
let originalOverflow = '';

function lockScroll(): void {
    if (lockCount++ === 0) {
        originalOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
    }
}

function unlockScroll(): void {
    if (--lockCount === 0) document.body.style.overflow = originalOverflow;
}

/**
 * Shared modal behaviour for Drawer and Dialog: Escape, close on pathname change,
 * focus trap, focus return and body scroll lock. Search-param changes do not close.
 */
export function useModal(
    open: boolean,
    onClose: () => void,
    panel: RefObject<HTMLElement | null>,
    body: RefObject<HTMLElement | null>,
): void {
    const { pathname } = useLocation();
    const onCloseRef = useRef(onClose);
    useEffect(() => {
        onCloseRef.current = onClose;
    });

    const openedAt = useRef(pathname);
    useEffect(() => {
        if (!open) {
            openedAt.current = pathname;
            return;
        }
        if (pathname !== openedAt.current) {
            openedAt.current = pathname;
            onCloseRef.current();
        }
    }, [open, pathname]);

    useEffect(() => {
        if (!open) return;

        const opener = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        const token = {};
        stack.push(token);
        lockScroll();

        const first = body.current?.querySelector<HTMLElement>(FOCUSABLE);
        (first ?? panel.current)?.focus();

        function onKeyDown(e: KeyboardEvent) {
            if (stack[stack.length - 1] !== token) return;
            if (e.key === 'Escape') {
                // A Menu (or anything else) inside the modal already handled this Escape.
                if (e.defaultPrevented) return;
                e.preventDefault();
                onCloseRef.current();
                return;
            }
            if (e.key !== 'Tab' || !panel.current) return;
            const items = Array.from(panel.current.querySelectorAll<HTMLElement>(FOCUSABLE));
            const firstItem = items[0];
            const lastItem = items[items.length - 1];
            if (!firstItem || !lastItem) {
                e.preventDefault();
                panel.current.focus();
                return;
            }
            const active = document.activeElement;
            if (e.shiftKey && (active === firstItem || active === panel.current || !panel.current.contains(active))) {
                e.preventDefault();
                lastItem.focus();
            } else if (!e.shiftKey && (active === lastItem || !panel.current.contains(active))) {
                e.preventDefault();
                firstItem.focus();
            }
        }
        document.addEventListener('keydown', onKeyDown);

        return () => {
            document.removeEventListener('keydown', onKeyDown);
            stack.splice(stack.indexOf(token), 1);
            unlockScroll();
            if (opener?.isConnected) opener.focus();
        };
    }, [open, panel, body]);
}
