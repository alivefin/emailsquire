import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { cx } from './cx';

export interface ToastInput {
    kind: 'success' | 'danger' | 'info';
    text: string;
    action?: { label: string; onClick: () => void };
}

interface ToastItem extends ToastInput {
    id: number;
}

interface ToastApi {
    show(t: ToastInput): void;
}

const ToastContext = createContext<ToastApi | null>(null);

const MAX_VISIBLE = 3;
const DOT: Record<ToastInput['kind'], string> = {
    success: 'bg-teal',
    danger: 'bg-danger',
    info: 'bg-muted',
};

export function ToastProvider({ children }: { children: ReactNode }) {
    const [toasts, setToasts] = useState<ToastItem[]>([]);
    const timers = useRef(new Map<number, ReturnType<typeof setTimeout>>());
    const nextId = useRef(0);

    const dismiss = useCallback((id: number) => {
        const timer = timers.current.get(id);
        if (timer !== undefined) clearTimeout(timer);
        timers.current.delete(id);
        setToasts((list) => list.filter((t) => t.id !== id));
    }, []);

    const show = useCallback(
        (t: ToastInput) => {
            const id = nextId.current++;
            setToasts((list) => {
                const next = [...list, { ...t, id }];
                for (const dropped of next.slice(0, Math.max(0, next.length - MAX_VISIBLE))) {
                    const timer = timers.current.get(dropped.id);
                    if (timer !== undefined) clearTimeout(timer);
                    timers.current.delete(dropped.id);
                }
                return next.slice(-MAX_VISIBLE);
            });
            timers.current.set(
                id,
                setTimeout(() => dismiss(id), t.action ? 8000 : 4500),
            );
        },
        [dismiss],
    );

    useEffect(() => {
        const map = timers.current;
        return () => {
            map.forEach(clearTimeout);
            map.clear();
        };
    }, []);

    const api = useMemo(() => ({ show }), [show]);

    return (
        <ToastContext.Provider value={api}>
            {children}
            {createPortal(
                <div
                    role="status"
                    aria-live="polite"
                    className="pointer-events-none fixed inset-x-0 bottom-4 z-[60] flex flex-col items-center gap-2 px-4"
                >
                    {toasts.map((t) => (
                        <div
                            key={t.id}
                            data-testid="toast"
                            className="pointer-events-auto flex w-full max-w-[460px] items-center gap-3 rounded-[10px] bg-ink px-3.5 py-3 text-[13.5px] text-white shadow-[0_12px_32px_rgba(0,0,0,0.25)]"
                        >
                            <span data-testid="toast-dot" className={cx('h-2 w-2 flex-none rounded-full', DOT[t.kind])} />
                            <span className="flex-1 wrap-anywhere">{t.text}</span>
                            {t.action && (
                                <button
                                    type="button"
                                    onClick={() => {
                                        t.action?.onClick();
                                        dismiss(t.id);
                                    }}
                                    className="flex-none font-bold text-teal hover:underline"
                                >
                                    {t.action.label}
                                </button>
                            )}
                        </div>
                    ))}
                </div>,
                document.body,
            )}
        </ToastContext.Provider>
    );
}

export function useToast(): ToastApi {
    const ctx = useContext(ToastContext);
    if (!ctx) throw new Error('useToast must be used inside ToastProvider');
    return ctx;
}
