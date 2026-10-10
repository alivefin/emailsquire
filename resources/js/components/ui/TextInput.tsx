import { useId } from 'react';
import type { InputHTMLAttributes } from 'react';
import { cx } from './cx';
import { Field } from './Field';
import { borderFor, controlClass } from './fieldStyles';

export interface TextInputProps extends InputHTMLAttributes<HTMLInputElement> {
    label?: string;
    error?: string;
    hint?: string;
}

export function TextInput({ label, error, hint, className, id, ...rest }: TextInputProps) {
    const auto = useId();
    const inputId = id ?? auto;
    return (
        <Field id={inputId} label={label} error={error} hint={hint} className={className}>
            <input
                id={inputId}
                aria-invalid={error ? true : undefined}
                aria-describedby={error || hint ? `${inputId}-msg` : undefined}
                className={cx(controlClass, borderFor(error))}
                {...rest}
            />
        </Field>
    );
}
