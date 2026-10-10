import type { ButtonHTMLAttributes } from 'react';
import { cx } from './cx';

export type ButtonVariant = 'primary' | 'dark' | 'secondary' | 'danger' | 'link';
export type ButtonSize = 'sm' | 'md' | 'lg';

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
    variant?: ButtonVariant;
    size?: ButtonSize;
    busy?: boolean;
}

const variants: Record<ButtonVariant, string> = {
    primary: 'bg-teal text-white hover:bg-teal-dark border border-transparent',
    dark: 'bg-ink text-white hover:bg-black border border-transparent',
    secondary: 'bg-white text-ink border border-line-strong hover:bg-subtle',
    danger: 'bg-white text-danger border border-danger-line hover:bg-danger-bg',
    link: 'bg-transparent text-teal-dark border border-transparent hover:underline px-0',
};

const sizes: Record<ButtonSize, string> = {
    sm: 'h-[30px] px-3 text-[12.5px] max-md:h-11 max-md:text-sm',
    md: 'h-9 px-3.5 text-[13px] max-md:h-12 max-md:text-sm',
    lg: 'h-10 px-4 text-sm max-md:h-[50px] max-md:text-[15px]',
};

export function Button({
    variant = 'secondary',
    size = 'md',
    busy = false,
    type = 'button',
    disabled,
    className,
    children,
    ...rest
}: ButtonProps) {
    return (
        <button
            type={type}
            disabled={disabled || busy}
            aria-busy={busy || undefined}
            className={cx(
                'inline-flex items-center justify-center gap-2 rounded-md font-semibold whitespace-nowrap transition-colors',
                'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal',
                'disabled:cursor-not-allowed disabled:opacity-50',
                busy && 'cursor-progress',
                variants[variant],
                sizes[size],
                className,
            )}
            {...rest}
        >
            {children}
        </button>
    );
}
