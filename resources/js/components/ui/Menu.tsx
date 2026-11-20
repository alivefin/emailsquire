import { useEffect, useId, useLayoutEffect, useRef, useState } from 'react';
import type { CSSProperties, KeyboardEvent } from 'react';
import { createPortal } from 'react-dom';
import { cx } from './cx';

export interface MenuItem {
    label: string;
    onSelect: () => void;
    danger?: boolean;
    disabled?: boolean;
}

export interface MenuProps {
    items: MenuItem[];
    'aria-label': string;
    className?: string;
}

/** Space between the trigger and the menu, and the least room the menu keeps from the window's edges. */
const GAP = 4;
const EDGE = 8;

const HIDDEN: CSSProperties = { position: 'fixed', visibility: 'hidden' };

/**
 * Where the open menu goes: right-aligned to the trigger and below it, or above it when the window has
 * no room below and more room above. When neither side fits, it takes the larger side and scrolls.
 */
function placement(trigger: DOMRect, menuHeight: number): CSSProperties {
    const viewportHeight = document.documentElement.clientHeight || window.innerHeight;
    const viewportWidth = document.documentElement.clientWidth || window.innerWidth;
    const right = Math.max(viewportWidth - trigger.right, EDGE);
    const below = viewportHeight - trigger.bottom - GAP - EDGE;
    const above = trigger.top - GAP - EDGE;
    if (menuHeight <= below || below >= above) {
        return { position: 'fixed', right, top: trigger.bottom + GAP, maxHeight: Math.max(below, 0) };
    }
    return { position: 'fixed', right, top: Math.max(trigger.top - GAP - menuHeight, EDGE), maxHeight: above };
}

export function Menu({ items, 'aria-label': ariaLabel, className }: MenuProps) {
    const [open, setOpen] = useState(false);
    const [style, setStyle] = useState<CSSProperties>(HIDDEN);
    const root = useRef<HTMLDivElement>(null);
    const trigger = useRef<HTMLButtonElement>(null);
    const menu = useRef<HTMLDivElement>(null);
    const itemRefs = useRef<(HTMLButtonElement | null)[]>([]);
    const menuId = useId();

    const enabled = () => items.map((it, i) => (it.disabled ? -1 : i)).filter((i) => i >= 0);

    function focusItem(index: number | undefined) {
        if (index !== undefined) itemRefs.current[index]?.focus({ preventScroll: true });
    }

    function close(returnFocus: boolean) {
        setOpen(false);
        if (returnFocus) trigger.current?.focus();
    }

    // The menu renders in <body> with fixed positioning, so no scroll box around the trigger (a table
    // wider than its column, a card list) can clip it. It follows the trigger on scroll and resize, and
    // stays hidden until placed, before the first paint.
    useLayoutEffect(() => {
        if (!open) {
            setStyle(HIDDEN);
            return;
        }
        function place() {
            if (trigger.current && menu.current) {
                setStyle(placement(trigger.current.getBoundingClientRect(), menu.current.getBoundingClientRect().height));
            }
        }
        place();
        window.addEventListener('scroll', place, true);
        window.addEventListener('resize', place);
        return () => {
            window.removeEventListener('scroll', place, true);
            window.removeEventListener('resize', place);
        };
    }, [open]);

    useEffect(() => {
        if (!open) return;
        focusItem(enabled()[0]);

        function onPointerDown(e: MouseEvent) {
            const target = e.target as Node;
            if (!root.current?.contains(target) && !menu.current?.contains(target)) setOpen(false);
        }
        document.addEventListener('mousedown', onPointerDown);
        return () => document.removeEventListener('mousedown', onPointerDown);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    // Key events from the menu reach this handler through the portal, along the React tree.
    function onKeyDown(e: KeyboardEvent<HTMLDivElement>) {
        if (e.key === 'Escape') {
            e.preventDefault();
            close(true);
            return;
        }
        if (e.key === 'Tab') {
            // The menu sits at the end of <body>: from the trigger, Tab moves on to the trigger's neighbour.
            close(true);
            return;
        }
        const list = enabled();
        const at = list.indexOf(itemRefs.current.findIndex((el) => el === document.activeElement));
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            focusItem(list[(at + 1) % list.length]);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            focusItem(list[(at - 1 + list.length) % list.length]);
        } else if (e.key === 'Home') {
            e.preventDefault();
            focusItem(list[0]);
        } else if (e.key === 'End') {
            e.preventDefault();
            focusItem(list[list.length - 1]);
        }
    }

    return (
        <div ref={root} className={cx('relative inline-block', className)} onKeyDown={open ? onKeyDown : undefined}>
            <button
                ref={trigger}
                type="button"
                aria-label={ariaLabel}
                aria-haspopup="menu"
                aria-expanded={open}
                aria-controls={open ? menuId : undefined}
                onClick={() => setOpen((o) => !o)}
                className="grid size-[30px] place-items-center rounded-md text-body hover:bg-line-soft focus-visible:outline-2 focus-visible:outline-teal max-md:size-11"
            >
                <svg aria-hidden="true" width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
                    <circle cx="8" cy="3" r="1.4" />
                    <circle cx="8" cy="8" r="1.4" />
                    <circle cx="8" cy="13" r="1.4" />
                </svg>
            </button>
            {open &&
                createPortal(
                    <div
                        ref={menu}
                        id={menuId}
                        role="menu"
                        aria-label={ariaLabel}
                        style={style}
                        className="z-30 min-w-44 overflow-y-auto rounded-lg border border-line bg-white py-1 shadow-lg"
                    >
                        {items.map((item, i) => (
                            <button
                                key={item.label}
                                ref={(el) => {
                                    itemRefs.current[i] = el;
                                }}
                                type="button"
                                role="menuitem"
                                tabIndex={-1}
                                disabled={item.disabled}
                                onClick={() => {
                                    // Focus goes back to the trigger first, so a drawer or dialog the item opens
                                    // records the trigger, not <body>, as the element to return focus to.
                                    close(true);
                                    item.onSelect();
                                }}
                                className={cx(
                                    'block w-full px-3 py-2 text-left text-[13px] font-medium hover:bg-subtle focus:bg-subtle focus:outline-none disabled:opacity-40',
                                    item.danger ? 'text-danger' : 'text-ink',
                                )}
                            >
                                {item.label}
                            </button>
                        ))}
                    </div>,
                    document.body,
                )}
        </div>
    );
}
