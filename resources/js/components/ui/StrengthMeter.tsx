import type { HTMLAttributes } from 'react';
import type { Score } from '../../lib/passwordStrength';
import { cx } from './cx';

export interface StrengthMeterProps extends HTMLAttributes<HTMLDivElement> {
    score: Score;
    /** True while the password field is empty: shows the requirement hint instead of a verdict. */
    empty?: boolean;
}

const SEGMENT: Record<number, string> = { 1: 'bg-danger', 2: 'bg-warn-bar', 3: 'bg-teal', 4: 'bg-teal-dark' };
const MESSAGES = [
    'Too weak — use 12+ characters with a number or symbol.',
    'Too weak — use 12+ characters with a number or symbol.',
    'Weak — add length or a symbol.',
    'Good.',
    'Strong.',
] as const;

export function StrengthMeter({ score, empty = false, className, ...rest }: StrengthMeterProps) {
    const message = empty ? 'At least 12 characters, not just letters.' : MESSAGES[score];
    const tone = empty ? 'text-muted' : score <= 1 ? 'text-danger' : score === 2 ? 'text-warn' : 'text-teal-dark';
    return (
        <div className={className} {...rest}>
            <div
                role="meter"
                aria-label="Password strength"
                aria-valuemin={0}
                aria-valuemax={4}
                aria-valuenow={score}
                aria-valuetext={message}
                className="flex gap-1"
            >
                {[0, 1, 2, 3].map((i) => (
                    <div
                        key={i}
                        data-testid="strength-segment"
                        data-filled={score > i}
                        className={cx('h-1 flex-1 rounded-sm', score > i ? SEGMENT[score] : 'bg-line')}
                    />
                ))}
            </div>
            <p className={cx('mt-1 text-[12.5px]', tone)}>{message}</p>
        </div>
    );
}
