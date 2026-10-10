import { useId } from 'react';
import { useTranslation } from 'react-i18next';
import { ApiProblem, fieldErrors, problemMessage } from '../../api/problems';
import { cx } from '../../components/ui/cx';
import { borderFor, controlClass, labelClass } from '../../components/ui/fieldStyles';
import { Select } from '../../components/ui/Select';
import type { Locale } from '../../i18n';

/**
 * The fields an invitation form shares between the Access tab's share dialog and the Users screen's
 * invite dialog: the role choice, the note with its counter and the email language.
 */

/** The most characters a note may have (the `invitations.note` column). */
export const NOTE_MAX = 500;

export interface RoleOption<R extends string> {
    value: R;
    label: string;
    hint: string;
}

interface RoleChoiceProps<R extends string> {
    options: RoleOption<R>[];
    value: R;
    onChange: (role: R) => void;
    error?: string;
}

/** The role as a list of radio cards, each with what the role can do. */
export function RoleChoice<R extends string>({ options, value, onChange, error }: RoleChoiceProps<R>) {
    const { t } = useTranslation('access');
    const name = useId();
    return (
        <fieldset>
            <legend className={labelClass}>{t('shareDialog.role')}</legend>
            <div className="flex flex-col gap-2">
                {options.map((option) => (
                    <label
                        key={option.value}
                        className={cx(
                            'flex cursor-pointer items-start gap-2.5 rounded-md border px-3 py-2.5',
                            value === option.value ? 'border-teal bg-tint' : 'border-line-strong',
                        )}
                    >
                        <input
                            type="radio"
                            name={name}
                            value={option.value}
                            checked={value === option.value}
                            onChange={() => onChange(option.value)}
                            className="mt-0.5 size-4 flex-none accent-teal"
                        />
                        <span className="min-w-0">
                            <span className="block font-semibold text-ink">{option.label}</span>
                            <span className="block text-[12.5px] text-muted">{option.hint}</span>
                        </span>
                    </label>
                ))}
            </div>
            {error && (
                <p role="alert" className="mt-1 text-[12.5px] text-danger">
                    {error}
                </p>
            )}
        </fieldset>
    );
}

interface NoteFieldProps {
    value: string;
    onChange: (note: string) => void;
    error?: string;
}

/** The optional note for the invitation mail, with an "n / 500" counter. */
export function NoteField({ value, onChange, error }: NoteFieldProps) {
    const { t } = useTranslation('access');
    const id = useId();
    // UTF-16 units, as `maxLength` counts them, so the counter and the limit agree.
    const used = value.length;
    return (
        <div>
            <div className="flex items-baseline justify-between gap-3">
                <label htmlFor={id} className={labelClass}>
                    {t('shareDialog.note')}
                </label>
                <span className={cx('text-xs', used > NOTE_MAX ? 'text-danger' : 'text-muted')} aria-live="polite">
                    {t('shareDialog.noteCount', { used, max: NOTE_MAX })}
                </span>
            </div>
            <textarea
                id={id}
                rows={3}
                maxLength={NOTE_MAX}
                placeholder={t('shareDialog.notePlaceholder')}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                aria-invalid={error ? true : undefined}
                aria-describedby={error ? `${id}-msg` : undefined}
                className={cx(controlClass, borderFor(error), 'h-auto py-2 max-md:h-auto')}
            />
            {error && (
                <p id={`${id}-msg`} role="alert" className="mt-1 text-[12.5px] text-danger">
                    {error}
                </p>
            )}
        </div>
    );
}

interface EmailLanguageProps {
    value: Locale;
    onChange: (locale: Locale) => void;
    error?: string;
}

/** The language of the invitation mail; each language named in itself. */
export function EmailLanguageSelect({ value, onChange, error }: EmailLanguageProps) {
    const { t } = useTranslation('access');
    return (
        <Select
            label={t('shareDialog.language')}
            value={value}
            onChange={(e) => onChange(e.target.value === 'fi' ? 'fi' : 'en')}
            error={error}
        >
            <option value="en" lang="en">
                {t('shareDialog.english')}
            </option>
            <option value="fi" lang="fi">
                {t('shareDialog.finnish')}
            </option>
        </Select>
    );
}

/**
 * An invitation POST's failure as errors by field. Both 409s are about the address typed, so they show
 * under it in the server's own words (which say whether it is access or a pending invitation). A field the
 * form doesn't show becomes the form error.
 */
export function invitationErrors<F extends string>(e: unknown, fields: readonly F[], fallback: string): Partial<Record<F | 'email' | 'form', string>> {
    const out: Record<string, string> = {};
    if (!(e instanceof ApiProblem)) {
        out.form = fallback;
    } else if (e.code === 'already_has_access' || e.code === 'user_deactivated') {
        out.email = problemMessage(e);
    } else {
        const found = fieldErrors(e);
        if (Object.keys(found).length === 0) out.form = problemMessage(e);
        for (const [field, message] of Object.entries(found)) {
            if ((fields as readonly string[]).includes(field)) out[field] = message;
            else out.form ??= message;
        }
    }
    return out as Partial<Record<F | 'email' | 'form', string>>;
}
