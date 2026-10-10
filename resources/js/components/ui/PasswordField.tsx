import { useId, useState } from 'react';
import type { InputHTMLAttributes } from 'react';
import { generatePassword } from '../../lib/password';
import { scorePassword } from '../../lib/passwordStrength';
import { Button } from './Button';
import { cx } from './cx';
import { labelClass } from './fieldStyles';
import { StrengthMeter } from './StrengthMeter';

export interface PasswordFieldProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'value' | 'onChange' | 'type'> {
    value: string;
    onChange: (value: string) => void;
    label?: string;
}

export function PasswordField({ value, onChange, label = 'Password', className, id, ...rest }: PasswordFieldProps) {
    const auto = useId();
    const inputId = id ?? auto;
    const [shown, setShown] = useState(false);

    return (
        <div className={className}>
            <label htmlFor={inputId} className={labelClass}>
                {label}
            </label>
            <div className="flex gap-2 max-md:flex-col">
                <div className="flex h-11 flex-1 overflow-hidden rounded-md border border-line-strong bg-white focus-within:border-teal focus-within:ring-2 focus-within:ring-teal/20 max-md:h-12">
                    <input
                        id={inputId}
                        type={shown ? 'text' : 'password'}
                        value={value}
                        onChange={(e) => onChange(e.target.value)}
                        placeholder="Type or generate"
                        autoComplete="new-password"
                        autoCapitalize="none"
                        autoCorrect="off"
                        spellCheck={false}
                        className={cx(
                            'min-w-0 flex-1 border-0 bg-transparent px-3 font-mono text-sm outline-none placeholder:font-sans placeholder:text-faint max-md:text-base',
                        )}
                        {...rest}
                    />
                    <button
                        type="button"
                        onClick={() => setShown((s) => !s)}
                        className="w-12 border-l border-line bg-subtle text-xs font-semibold text-body hover:bg-line-soft max-md:w-14"
                    >
                        {shown ? 'Hide' : 'Show'}
                    </button>
                </div>
                <Button size="lg" className="h-11 max-md:h-11" onClick={() => onChange(generatePassword())}>
                    Generate
                </Button>
            </div>
            <StrengthMeter className="mt-2" score={scorePassword(value)} empty={value === ''} />
        </div>
    );
}
