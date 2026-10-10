import type { ReactNode } from 'react';
import { labelClass } from './fieldStyles';

interface FieldProps {
    id: string;
    label?: string;
    error?: string;
    hint?: string;
    className?: string;
    children: ReactNode;
}

/** Label, control slot and the error or hint line shared by the form primitives. */
export function Field({ id, label, error, hint, className, children }: FieldProps) {
    return (
        <div className={className}>
            {label && (
                <label htmlFor={id} className={labelClass}>
                    {label}
                </label>
            )}
            {children}
            {error ? (
                <p id={`${id}-msg`} role="alert" className="mt-1 text-[12.5px] text-danger">
                    {error}
                </p>
            ) : hint ? (
                <p id={`${id}-msg`} className="mt-1 text-[12.5px] text-muted">
                    {hint}
                </p>
            ) : null}
        </div>
    );
}
