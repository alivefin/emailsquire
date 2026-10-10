import { useEffect, useRef, useState, type FormEvent } from 'react';
import i18next from 'i18next';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { apiFetch } from '../../api/client';
import { ApiProblem, fieldErrors, problemMessage } from '../../api/problems';
import { Button } from '../../components/ui/Button';
import { TextInput } from '../../components/ui/TextInput';
import { useLocale } from '../../i18n';
import { AuthCard, AuthError, AuthHeading, Spinner } from './AuthCard';

const BACK_ARROW = '←';

function BackToSignIn() {
    const { t } = useTranslation('auth');
    return (
        <Link to="/signin" className="mt-4 inline-block text-[12.5px] font-semibold text-muted hover:text-ink max-md:text-sm">
            <span aria-hidden>{BACK_ARROW} </span>
            {t('forgot.back')}
        </Link>
    );
}

/**
 * `/forgot-password`: asks for the address and emails a reset link. The server answers the same 202
 * whether or not the account exists, so the page always shows the same confirmation after a 2xx. Only a
 * refusal that says nothing about the account (throttled, malformed address, unreachable) shows an error.
 */
export function ForgotPasswordPage() {
    const { t } = useTranslation('auth');
    const locale = useLocale();
    const [email, setEmail] = useState('');
    const [busy, setBusy] = useState(false);
    const [sent, setSent] = useState(false);
    const [fieldError, setFieldError] = useState<string | undefined>();
    const [formError, setFormError] = useState<string | null>(null);
    const sending = useRef(false);
    const sentHeading = useRef<HTMLHeadingElement>(null);

    // Errors are in the language they arrived in; drop them when the language changes.
    useEffect(() => {
        setFieldError(undefined);
        setFormError(null);
    }, [locale]);

    useEffect(() => {
        if (sent) sentHeading.current?.focus();
    }, [sent]);

    const submit = async (e: FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        const address = email.trim();
        if (address === '' || sending.current) return;
        sending.current = true;
        setBusy(true);
        setFieldError(undefined);
        setFormError(null);
        try {
            await apiFetch('/auth/forgot-password', { method: 'POST', json: { email: address } });
            setSent(true);
        } catch (err) {
            if (err instanceof ApiProblem) {
                const field = fieldErrors(err).email;
                if (field !== undefined) setFieldError(field);
                else setFormError(problemMessage(err));
            } else {
                setFormError(i18next.t('unreachableRetry', { ns: 'errors' }));
            }
            setBusy(false);
        } finally {
            sending.current = false;
        }
    };

    if (sent) {
        return (
            <AuthCard>
                <AuthHeading title={t('forgot.sentTitle')} focusRef={sentHeading} />
                <p role="status" className="mt-3 text-[13.5px] leading-relaxed text-body">
                    {t('forgot.sent')}
                </p>
                <BackToSignIn />
            </AuthCard>
        );
    }

    return (
        <AuthCard>
            <AuthHeading title={t('forgot.title')} subtitle={t('forgot.subtitle')} />
            <form noValidate onSubmit={(e) => void submit(e)}>
                <TextInput
                    className="mt-5"
                    label={t('forgot.email')}
                    type="email"
                    name="email"
                    autoComplete="username"
                    autoCapitalize="none"
                    spellCheck={false}
                    placeholder={t('signIn.emailPlaceholder')}
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    error={fieldError}
                />
                {formError !== null && <AuthError>{formError}</AuthError>}
                <Button type="submit" variant="primary" size="lg" busy={busy} className="mt-[18px] h-[46px] w-full">
                    {busy && <Spinner />}
                    {t('forgot.submit')}
                </Button>
            </form>
            <BackToSignIn />
        </AuthCard>
    );
}
