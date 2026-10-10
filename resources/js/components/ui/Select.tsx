import { useId } from 'react';
import type { SelectHTMLAttributes } from 'react';
import { cx } from './cx';
import { Field } from './Field';
import { borderFor, controlClass } from './fieldStyles';

export interface SelectProps extends SelectHTMLAttributes<HTMLSelectElement> {
    label?: string;
    error?: string;
    hint?: string;
}

export function Select({ label, error, hint, className, id, children, ...rest }: SelectProps) {
    const auto = useId();
    const selectId = id ?? auto;
    return (
        <Field id={selectId} label={label} error={error} hint={hint} className={className}>
            <select
                id={selectId}
                aria-invalid={error ? true : undefined}
                aria-describedby={error || hint ? `${selectId}-msg` : undefined}
                className={cx(controlClass, borderFor(error))}
                {...rest}
            >
                {children}
            </select>
        </Field>
    );
}
