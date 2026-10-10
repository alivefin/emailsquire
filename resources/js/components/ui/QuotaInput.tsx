import { useEffect, useId, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { GIB, MIB, quotaProblem, toBytes } from '../../lib/quota';
import type { QuotaUnit } from '../../lib/quota';
import { Button } from './Button';
import { cx } from './cx';
import { borderFor, controlClass, labelClass } from './fieldStyles';

export interface QuotaValue {
    bytes: number | null;
    problem: string | null;
}

export interface QuotaInputProps {
    onChange: (value: QuotaValue) => void;
    /** Initial limit in bytes. Defaults to 5 GB. */
    defaultBytes?: number;
    /** Space already used; the limit cannot go below it. */
    usedBytes?: number | null;
    label?: string;
    className?: string;
}

const PRESETS = [1, 5, 10];

function initial(bytes: number): { text: string; unit: QuotaUnit } {
    return bytes % GIB === 0
        ? { text: String(bytes / GIB), unit: 'GB' }
        : { text: String(Math.round((bytes / MIB) * 100) / 100), unit: 'MB' };
}

export function QuotaInput({ onChange, defaultBytes = 5 * GIB, usedBytes, label, className }: QuotaInputProps) {
    const { t } = useTranslation('common');
    const id = useId();
    const [state, setState] = useState(() => initial(defaultBytes));
    const bytes = toBytes(state.text, state.unit);
    const problem = quotaProblem(bytes, usedBytes);

    const latest = useRef(onChange);
    useEffect(() => {
        latest.current = onChange;
    });
    useEffect(() => {
        latest.current({ bytes, problem });
    }, [bytes, problem]);

    return (
        <div className={className}>
            <label htmlFor={id} className={labelClass}>
                {label ?? t('quota.label')}
            </label>
            <div className="flex gap-2">
                <input
                    id={id}
                    type="text"
                    inputMode="decimal"
                    value={state.text}
                    onChange={(e) => setState((s) => ({ ...s, text: e.target.value }))}
                    aria-invalid={problem ? true : undefined}
                    aria-describedby={problem ? `${id}-msg` : undefined}
                    className={cx(controlClass, borderFor(problem ?? undefined), 'flex-1')}
                />
                <select
                    aria-label={t('quota.unit')}
                    value={state.unit}
                    onChange={(e) => setState((s) => ({ ...s, unit: e.target.value as QuotaUnit }))}
                    className={cx(controlClass, 'w-20 shrink-0 border-line-strong')}
                >
                    <option value="MB">MB</option>
                    <option value="GB">GB</option>
                </select>
            </div>
            <div className="mt-2 flex gap-2">
                {PRESETS.map((gb) => (
                    <Button key={gb} size="sm" onClick={() => setState({ text: String(gb), unit: 'GB' })}>
                        {gb} GB
                    </Button>
                ))}
            </div>
            {problem && (
                <p id={`${id}-msg`} role="alert" className="mt-1 text-[12.5px] text-danger">
                    {problem}
                </p>
            )}
        </div>
    );
}
