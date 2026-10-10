import { useId } from 'react';
import { cx } from './cx';
import { Field } from './Field';
import { borderFor } from './fieldStyles';
import type { TextInputProps } from './TextInput';

export interface AddonInputProps extends TextInputProps {
    /** Shown after the input as a fixed "@domain" addon. */
    domain: string;
}

export function AddonInput({ domain, label, error, hint, className, id, ...rest }: AddonInputProps) {
    const auto = useId();
    const inputId = id ?? auto;
    return (
        <Field id={inputId} label={label} error={error} hint={hint} className={className}>
            <div
                className={cx(
                    'flex h-11 overflow-hidden rounded-md border bg-white focus-within:border-teal focus-within:ring-2 focus-within:ring-teal/20 max-md:h-12',
                    borderFor(error),
                )}
            >
                <input
                    id={inputId}
                    aria-invalid={error ? true : undefined}
                    aria-describedby={error || hint ? `${inputId}-msg` : undefined}
                    autoCapitalize="none"
                    autoCorrect="off"
                    spellCheck={false}
                    className="min-w-0 flex-1 border-0 bg-transparent px-3 text-sm text-ink outline-none placeholder:text-faint max-md:text-base"
                    {...rest}
                />
                <span className="flex items-center border-l border-line bg-subtle px-3 text-sm text-body">@{domain}</span>
            </div>
        </Field>
    );
}
