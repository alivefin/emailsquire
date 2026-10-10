import { useEffect, useId, useRef, useState, type FormEvent } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import i18next from 'i18next';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router';
import { apiFetch } from '../../api/client';
import { ApiProblem, fieldErrors, problemMessage } from '../../api/problems';
import { queryKeys } from '../../api/types';
import { Button } from '../../components/ui/Button';
import { PasswordField } from '../../components/ui/PasswordField';
import { TextInput } from '../../components/ui/TextInput';
import { useLocale } from '../../i18n';
import { scorePassword } from '../../lib/passwordStrength';
import { AuthBanner, AuthCard, AuthHeading, Spinner } from './AuthCard';
import type { SignInState } from './SignInPage';

type Errors = Partial<Record<'token' | 'email' | 'password' | 'password_confirmation' | 'form', string>>;

function toErrors(e: unknown): Errors {
    if (!(e instanceof ApiProblem)) return { form: i18next.t('unreachableRetry', { ns: 'errors' }) };
    const fields = fieldErrors(e);
    if (Object.keys(fields).length === 0) return { form: problemMessage(e) };
    return fields;
}

/**
 * `/reset-password/:token?email=`: the link from the forgotten-password email (30 minutes, once). The
 * server sets the password, ends every session of the user and signs nobody in, so the page clears the
 * cache and sends the visitor to sign in with a "password changed" banner.
 */
export function ResetPasswordPage() {
    const { t } = useTranslation('auth');
    const { token = '' } = useParams();
    const [params] = useSearchParams();
    const email = params.get('email') ?? '';
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const passwordMsgId = useId();

    const [password, setPassword] = useState('');
    const [confirmation, setConfirmation] = useState('');
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Errors>({});
    const sending = useRef(false);
    const locale = useLocale();

    // Server errors are in the language they arrived in; drop them when the language changes.
    useEffect(() => setErrors({}), [locale]);

    const mismatch = confirmation !== '' && confirmation.length >= password.length && confirmation !== password;
    const ready =
        email !== '' && scorePassword(password) >= 3 && [...password].length >= 12 && confirmation === password;

    const submit = async (e: FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        if (!ready || sending.current) return;
        sending.current = true;
        setBusy(true);
        setErrors({});
        try {
            await apiFetch('/auth/reset-password', {
                method: 'POST',
                json: { token, email, password, password_confirmation: confirmation },
            });
            // Every session of this user is gone, this browser's too: forget what it showed.
            queryClient.clear();
            queryClient.setQueryData(queryKeys.me, null);
            const state: SignInState = { passwordChanged: true };
            void navigate('/signin', { replace: true, state });
        } catch (err) {
            setErrors(toErrors(err));
            setBusy(false);
        } finally {
            sending.current = false;
        }
    };

    const linkProblem = errors.token ?? (email === '' ? t('reset.missingEmail') : null);

    return (
        <AuthCard>
            {linkProblem !== null && (
                <AuthBanner tone="danger">
                    {linkProblem}{' '}
                    <Link to="/forgot-password" className="font-semibold text-teal-dark underline">
                        {t('reset.requestNew')}
                    </Link>
                </AuthBanner>
            )}
            {linkProblem === null && errors.form !== undefined && <AuthBanner tone="danger">{errors.form}</AuthBanner>}
            <AuthHeading title={t('reset.title')} subtitle={t('reset.subtitle')} />
            <form noValidate onSubmit={(e) => void submit(e)}>
                <TextInput className="mt-5" label={t('reset.email')} type="email" value={email} readOnly error={errors.email} />
                <PasswordField
                    className="mt-3.5"
                    label={t('reset.newPassword')}
                    value={password}
                    onChange={setPassword}
                    allowGenerate={false}
                    aria-invalid={errors.password ? true : undefined}
                    aria-describedby={errors.password ? passwordMsgId : undefined}
                />
                {errors.password && (
                    <p id={passwordMsgId} role="alert" className="mt-1 text-[12.5px] text-danger">
                        {errors.password}
                    </p>
                )}
                <TextInput
                    className="mt-3.5"
                    label={t('reset.confirm')}
                    type="password"
                    autoComplete="new-password"
                    value={confirmation}
                    onChange={(e) => setConfirmation(e.target.value)}
                    error={errors.password_confirmation ?? (mismatch ? t('reset.mismatch') : undefined)}
                />
                <Button
                    type="submit"
                    variant="primary"
                    size="lg"
                    busy={busy}
                    disabled={!ready}
                    className="mt-[18px] h-[46px] w-full"
                >
                    {busy && <Spinner />}
                    {t('reset.submit')}
                </Button>
            </form>
        </AuthCard>
    );
}
