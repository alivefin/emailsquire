import type { ButtonHTMLAttributes } from 'react';
import { cx } from './cx';

export interface SwitchProps extends Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'onChange' | 'role'> {
    checked: boolean;
    onChange: (checked: boolean) => void;
    label: string;
}

export function Switch({ checked, onChange, label, className, ...rest }: SwitchProps) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            onClick={() => onChange(!checked)}
            className={cx(
                'inline-flex items-center gap-2.5 text-left text-[13px] font-semibold text-ink disabled:opacity-50',
                'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal',
                className,
            )}
            {...rest}
        >
            <span
                aria-hidden="true"
                className={cx(
                    'relative h-5 w-9 shrink-0 rounded-full transition-colors',
                    checked ? 'bg-teal' : 'bg-line-strong',
                )}
            >
                <span
                    className={cx(
                        'absolute top-0.5 left-0.5 size-4 rounded-full bg-white shadow transition-transform',
                        checked && 'translate-x-4',
                    )}
                />
            </span>
            {label}
        </button>
    );
}
