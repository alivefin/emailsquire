import { useId, useState } from 'react';
import type { KeyboardEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { Chip, type ChipKind } from './Chip';
import { cx } from './cx';
import { Field } from './Field';
import { borderFor } from './fieldStyles';

export interface ChipInputProps {
    value: string[];
    onChange(next: string[]): void;
    /** An error message for the entry, or null when it may be added. Gets the trimmed, lowercased text. */
    validate(a: string): string | null;
    placeholder?: string;
    label?: string;
    id?: string;
    /** The chip's colour; `server` when left out. */
    kindOf?: (a: string) => ChipKind;
    /** An error from outside, such as the server's, shown when the input has none of its own. */
    error?: string;
    hint?: string;
    /** Reports the uncommitted text, so a form can tell whether something is still being typed. */
    onDraftChange?: (text: string) => void;
}

const DEFAULT_KIND: ChipKind = 'server';

/**
 * A list of addresses as removable chips. Enter, a comma or leaving the field adds the typed text;
 * Backspace in an empty field removes the last chip. Duplicates are dropped; an invalid entry stays
 * in the field with its error.
 */
export function ChipInput({ value, onChange, validate, placeholder, label, id, kindOf, error, hint, onDraftChange }: ChipInputProps) {
    const { t } = useTranslation('common');
    const auto = useId();
    const inputId = id ?? auto;
    const [text, setTextState] = useState('');
    const [own, setOwn] = useState<string | null>(null);

    function setText(next: string) {
        setTextState(next);
        onDraftChange?.(next);
    }

    /** Adds the entries in order and returns the text that could not be added, with its error. */
    function addAll(entries: string[]): { rest: string; problem: string | null } {
        const next = [...value];
        for (let i = 0; i < entries.length; i++) {
            const a = (entries[i] ?? '').trim().toLowerCase();
            if (a === '') continue;
            if (next.includes(a)) continue;
            const problem = validate(a);
            if (problem !== null) {
                if (next.length !== value.length) onChange(next);
                return { rest: entries.slice(i).join(',').trim(), problem };
            }
            next.push(a);
        }
        if (next.length !== value.length) onChange(next);
        return { rest: '', problem: null };
    }

    function commit() {
        const { rest, problem } = addAll([text]);
        setText(rest);
        setOwn(problem);
    }

    function onKeyDown(e: KeyboardEvent<HTMLInputElement>) {
        if (e.key === 'Enter' || e.key === ',') {
            e.preventDefault();
            commit();
        } else if (e.key === 'Backspace' && text === '' && value.length > 0) {
            e.preventDefault();
            onChange(value.slice(0, -1));
        }
    }

    function onInput(raw: string) {
        setOwn(null);
        if (!raw.includes(',')) {
            setText(raw);
            return;
        }
        // Pasted or typed lists: everything before the last comma is added now.
        const parts = raw.split(',');
        const last = parts.pop() ?? '';
        const { rest, problem } = addAll(parts);
        setText(rest === '' ? last.trimStart() : `${rest},${last}`);
        setOwn(problem);
    }

    const shown = own ?? error;

    return (
        <Field id={inputId} label={label} error={shown} hint={hint}>
            <div
                className={cx(
                    'flex min-h-11 flex-wrap items-center gap-1.5 rounded-md border bg-white px-1.5 py-[5px] focus-within:border-teal focus-within:ring-2 focus-within:ring-teal/20 max-md:min-h-12',
                    borderFor(shown),
                )}
            >
                {value.map((a) => {
                    const kind = kindOf?.(a) ?? DEFAULT_KIND;
                    return (
                        <Chip key={a} kind={kind} title={t(`chip.kind.${kind}`)} onRemove={() => onChange(value.filter((x) => x !== a))}>
                            {a}
                        </Chip>
                    );
                })}
                <input
                    id={inputId}
                    value={text}
                    placeholder={placeholder}
                    aria-invalid={shown ? true : undefined}
                    aria-describedby={shown || hint ? `${inputId}-msg` : undefined}
                    autoCapitalize="none"
                    autoCorrect="off"
                    autoComplete="off"
                    spellCheck={false}
                    inputMode="email"
                    onChange={(e) => onInput(e.target.value)}
                    onKeyDown={onKeyDown}
                    onBlur={() => {
                        if (text.trim() !== '') commit();
                    }}
                    className="h-8 min-w-[140px] flex-1 border-0 bg-transparent px-1.5 text-sm text-ink outline-none placeholder:text-faint max-md:text-base"
                />
            </div>
        </Field>
    );
}
