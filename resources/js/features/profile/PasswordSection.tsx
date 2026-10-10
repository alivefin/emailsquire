import { useEffect, useId, useRef, useState, type FormEvent } from 'react';
import i18next from 'i18next';
import { useTranslation } from 'react-i18next';
import { apiFetch } from '../../api/client';
import { ApiProblem, fieldErrors, problemMessage } from '../../api/problems';
import { Button } from '../../components/ui/Button';
import { PasswordField } from '../../components/ui/PasswordField';
import { TextInput } from '../../components/ui/TextInput';
import { useToast } from '../../components/ui/Toast';
import { useLocale } from '../../i18n';
import { scorePassword } from '../../lib/passwordStrength';
import { ProfileSection } from './ProfileSection';

type Errors = Partial<Record<'current_password' | 'password' | 'password_confirmation' | 'form', string>>;

function toErrors(e: unknown): Errors {
    if (!(e instanceof ApiProblem)) return { form: i18next.t('unreachableRetry', { ns: 'errors' }) };
    const fields = fieldErrors(e);
    if (Object.keys(fields).length === 0) return { form: problemMessage(e) };
    return fields;
}

/**
 * Change password: the current one, a new one with the strength meter (no Generate: the user must
 * remember it) and its confirmation. The server signs out every other session and keeps this one.
 */
export function PasswordSection() {
    const { t } = useTranslation('profile');
    const toast = useToast();
    const locale = useLocale();
    const newMsgId = useId();
    const [current, setCurrent] = useState('');
    const [password, setPassword] = useState('');
    const [confirmation, setConfirmation] = useState('');
    const [errors, setErrors] = useState<Errors>({});
    const [busy, setBusy] = useState(false);
    const sending = useRef(false);

    // Server errors are in the language they arrived in; drop them when the language changes.
    useEffect(() => setErrors({}), [locale]);

    const mismatch = confirmation !== '' && confirmation.length >= password.length && confirmation !== password;
    const ready = current !== '' && scorePassword(password) >= 3 && [...password].length >= 12 && confirmation === password;

    async function submit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        if (!ready || sending.current) return;
        sending.current = true;
        setBusy(true);
        setErrors({});
        try {
            await apiFetch('/me/password', {
                method: 'PUT',
                json: { current_password: current, password, password_confirmation: confirmation },
            });
            setCurrent('');
            setPassword('');
            setConfirmation('');
            toast.show({ kind: 'success', text: t('password.changed') });
        } catch (err) {
            setErrors(toErrors(err));
        } finally {
            sending.current = false;
            setBusy(false);
        }
    }

    return (
        <ProfileSection title={t('password.title')} subtitle={t('password.subtitle')}>
            <form noValidate onSubmit={(e) => void submit(e)} className="flex max-w-[460px] flex-col gap-3.5">
                <TextInput
                    label={t('password.current')}
                    type="password"
                    autoComplete="current-password"
                    value={current}
                    onChange={(e) => setCurrent(e.target.value)}
                    error={errors.current_password}
                />
                <div>
                    <PasswordField
                        label={t('password.new')}
                        value={password}
                        onChange={setPassword}
                        allowGenerate={false}
                        aria-invalid={errors.password ? true : undefined}
                        aria-describedby={errors.password ? newMsgId : undefined}
                    />
                    {errors.password && (
                        <p id={newMsgId} role="alert" className="mt-1 text-[12.5px] text-danger">
                            {errors.password}
                        </p>
                    )}
                </div>
                <TextInput
                    label={t('password.confirm')}
                    type="password"
                    autoComplete="new-password"
                    value={confirmation}
                    onChange={(e) => setConfirmation(e.target.value)}
                    error={errors.password_confirmation ?? (mismatch ? t('password.mismatch') : undefined)}
                />
                {errors.form !== undefined && (
                    <p role="alert" className="text-[12.5px] text-danger">
                        {errors.form}
                    </p>
                )}
                <div>
                    <Button type="submit" variant="primary" busy={busy} disabled={!ready}>
                        {t('password.submit')}
                    </Button>
                </div>
            </form>
        </ProfileSection>
    );
}
