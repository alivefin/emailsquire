import { useId, useState, type FormEvent } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { apiFetch } from '../../api/client';
import { ApiProblem, fieldErrors, isStepUpCancelled, problemMessage } from '../../api/problems';
import type { Json, Schemas } from '../../api/types';
import { Button } from '../../components/ui/Button';
import { cx } from '../../components/ui/cx';
import { Dialog } from '../../components/ui/Dialog';
import { borderFor, controlClass, labelClass } from '../../components/ui/fieldStyles';
import { Select } from '../../components/ui/Select';
import { TextInput } from '../../components/ui/TextInput';
import { useToast } from '../../components/ui/Toast';
import { currentLocale, type Locale } from '../../i18n';
import { useSingleFlight } from '../domain/shared';
import { accessPath, refreshAfterAccessWrite, type GrantRole } from './shared';

type Created = Json<'/domains/{domain}/access/invitations', 'post'>;
type Body = Schemas['StoreDomainInvitationRequest'];
type Errors = Partial<Record<'email' | 'role' | 'note' | 'locale' | 'form', string>>;

/** The most characters a note may have (the `invitations.note` column). */
export const NOTE_MAX = 500;

const ROLES: GrantRole[] = ['viewer', 'manager'];

export interface ShareDialogProps {
    open: boolean;
    onClose: () => void;
    /** Punycode domain name, as the API wants it. */
    domain: string;
    /** The domain as shown to people. */
    unicode: string;
}

export function ShareDialog({ open, ...rest }: ShareDialogProps) {
    // Mounting the form only while open starts every share from empty fields.
    return open ? <ShareForm {...rest} /> : null;
}

function toErrors(e: unknown, fallback: string): Errors {
    if (!(e instanceof ApiProblem)) return { form: fallback };
    // Both conflicts are about the address typed: show them under it.
    if (e.code === 'already_has_access' || e.code === 'user_deactivated') return { email: problemMessage(e) };
    const fields = fieldErrors(e);
    if (Object.keys(fields).length > 0) {
        const { email, role, note, locale, ...other } = fields;
        const rest = Object.values(other)[0];
        return { email, role, note, locale, ...(rest === undefined ? {} : { form: rest }) };
    }
    return { form: problemMessage(e) };
}

function ShareForm({ onClose, domain, unicode }: Omit<ShareDialogProps, 'open'>) {
    const { t } = useTranslation('access');
    const queryClient = useQueryClient();
    const toast = useToast();
    const once = useSingleFlight();
    const formId = useId();
    const noteId = useId();
    const roleName = useId();

    const [email, setEmail] = useState('');
    const [role, setRole] = useState<GrantRole>('viewer');
    const [note, setNote] = useState('');
    const [locale, setLocale] = useState<Locale>(currentLocale);
    const [errors, setErrors] = useState<Errors>({});

    const share = useMutation({
        mutationFn: (json: Body) => apiFetch<Created>(accessPath(domain, 'invitations'), { method: 'POST', json }),
        onSuccess: async (_created, json) => {
            toast.show({ kind: 'success', text: t('shareDialog.sent', { email: json.email }) });
            onClose();
            await refreshAfterAccessWrite(queryClient);
        },
        onError: (e) => {
            // Closing "Confirm it's you" is a choice, not a failure: leave the form as it was.
            if (isStepUpCancelled(e)) return;
            setErrors(toErrors(e, t('shareDialog.failed')));
        },
    });

    function submit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        const address = email.trim();
        if (address === '') {
            setErrors({ email: t('shareDialog.emailRequired') });
            return;
        }
        setErrors({});
        const text = note.trim();
        const json: Body = { email: address, role, ...(text === '' ? {} : { note: text }), locale };
        once(() => share.mutateAsync(json));
    }

    // UTF-16 units, as `maxLength` counts them, so the counter and the limit agree.
    const used = note.length;

    return (
        <Dialog
            open
            onClose={onClose}
            title={t('shareDialog.title', { domain: unicode })}
            subtitle={t('shareDialog.subtitle')}
            footer={
                <>
                    <Button onClick={onClose}>{t('shareDialog.cancel')}</Button>
                    <Button type="submit" form={formId} variant="primary" busy={share.isPending}>
                        {t('shareDialog.send')}
                    </Button>
                </>
            }
        >
            <form id={formId} noValidate onSubmit={submit} className="flex flex-col gap-4">
                <TextInput
                    label={t('shareDialog.email')}
                    type="email"
                    autoComplete="off"
                    autoCapitalize="none"
                    spellCheck={false}
                    placeholder={t('shareDialog.emailPlaceholder')}
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    error={errors.email}
                />
                <fieldset>
                    <legend className={labelClass}>{t('shareDialog.role')}</legend>
                    <div className="flex flex-col gap-2">
                        {ROLES.map((value) => (
                            <label
                                key={value}
                                className={cx(
                                    'flex cursor-pointer items-start gap-2.5 rounded-md border px-3 py-2.5',
                                    role === value ? 'border-teal bg-tint' : 'border-line-strong',
                                )}
                            >
                                <input
                                    type="radio"
                                    name={roleName}
                                    value={value}
                                    checked={role === value}
                                    onChange={() => setRole(value)}
                                    className="mt-0.5 size-4 flex-none accent-teal"
                                />
                                <span className="min-w-0">
                                    <span className="block font-semibold text-ink">{t(`roles.${value}`)}</span>
                                    <span className="block text-[12.5px] text-muted">{t(`roleHints.${value}`)}</span>
                                </span>
                            </label>
                        ))}
                    </div>
                    {errors.role && (
                        <p role="alert" className="mt-1 text-[12.5px] text-danger">
                            {errors.role}
                        </p>
                    )}
                </fieldset>
                <div>
                    <div className="flex items-baseline justify-between gap-3">
                        <label htmlFor={noteId} className={labelClass}>
                            {t('shareDialog.note')}
                        </label>
                        <span className={cx('text-xs', used > NOTE_MAX ? 'text-danger' : 'text-muted')} aria-live="polite">
                            {t('shareDialog.noteCount', { used, max: NOTE_MAX })}
                        </span>
                    </div>
                    <textarea
                        id={noteId}
                        rows={3}
                        maxLength={NOTE_MAX}
                        placeholder={t('shareDialog.notePlaceholder')}
                        value={note}
                        onChange={(e) => setNote(e.target.value)}
                        aria-invalid={errors.note ? true : undefined}
                        aria-describedby={errors.note ? `${noteId}-msg` : undefined}
                        className={cx(controlClass, borderFor(errors.note), 'h-auto py-2 max-md:h-auto')}
                    />
                    {errors.note && (
                        <p id={`${noteId}-msg`} role="alert" className="mt-1 text-[12.5px] text-danger">
                            {errors.note}
                        </p>
                    )}
                </div>
                <Select
                    label={t('shareDialog.language')}
                    value={locale}
                    onChange={(e) => setLocale(e.target.value === 'fi' ? 'fi' : 'en')}
                    error={errors.locale}
                >
                    <option value="en" lang="en">
                        {t('shareDialog.english')}
                    </option>
                    <option value="fi" lang="fi">
                        {t('shareDialog.finnish')}
                    </option>
                </Select>
                {errors.form && (
                    <p role="alert" className="text-[12.5px] text-danger">
                        {errors.form}
                    </p>
                )}
            </form>
        </Dialog>
    );
}
