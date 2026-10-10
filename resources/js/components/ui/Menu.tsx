import { useEffect, useId, useRef, useState } from 'react';
import type { KeyboardEvent } from 'react';
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

export function Menu({ items, 'aria-label': ariaLabel, className }: MenuProps) {
    const [open, setOpen] = useState(false);
    const root = useRef<HTMLDivElement>(null);
    const trigger = useRef<HTMLButtonElement>(null);
    const itemRefs = useRef<(HTMLButtonElement | null)[]>([]);
    const menuId = useId();

    const enabled = () => items.map((it, i) => (it.disabled ? -1 : i)).filter((i) => i >= 0);

    function focusItem(index: number | undefined) {
        if (index !== undefined) itemRefs.current[index]?.focus();
    }

    function close(returnFocus: boolean) {
        setOpen(false);
        if (returnFocus) trigger.current?.focus();
    }

    useEffect(() => {
        if (!open) return;
        focusItem(enabled()[0]);

        function onPointerDown(e: MouseEvent) {
            if (root.current && !root.current.contains(e.target as Node)) setOpen(false);
        }
        document.addEventListener('mousedown', onPointerDown);
        return () => document.removeEventListener('mousedown', onPointerDown);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    function onKeyDown(e: KeyboardEvent<HTMLDivElement>) {
        if (e.key === 'Escape') {
            e.preventDefault();
            close(true);
            return;
        }
        if (e.key === 'Tab') {
            setOpen(false);
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
            {open && (
                <div
                    id={menuId}
                    role="menu"
                    aria-label={ariaLabel}
                    className="absolute right-0 z-20 mt-1 min-w-44 rounded-lg border border-line bg-white py-1 shadow-lg"
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
                </div>
            )}
        </div>
    );
}
