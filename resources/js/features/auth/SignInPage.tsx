import { useEffect, useRef, useState, type FormEvent } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Navigate, useSearchParams } from 'react-router';
import { apiFetch } from '../../api/client';
import i18next from 'i18next';
import { ApiProblem, fieldErrors, problemMessage } from '../../api/problems';
import { queryKeys } from '../../api/types';
import { Button } from '../../components/ui/Button';
import { TextInput } from '../../components/ui/TextInput';
import { useLocale } from '../../i18n';
import { PageSpinner } from '../../layout/PageSpinner';
import { AuthBanner, AuthCard, AuthError, AuthHeading, Spinner } from './AuthCard';
import { useMe, type Me } from './useMe';

/**
 * Returns `raw` when it is an internal path, else `/`. Rejects protocol-relative (`//`, `/\`) values,
 * control characters and backslashes (browsers strip or rewrite them), and `/signin` itself.
 */
export function safeNext(raw: string | null): string {
    if (raw === null || !raw.startsWith('/') || raw.startsWith('//')) return '/';
    for (const ch of raw) {
        const code = ch.charCodeAt(0);
        if (code < 0x20 || code === 0x7f || ch === '\\') return '/';
    }
    const path = raw.split(/[?#]/, 1)[0];
    if (path === '/signin' || path === '/signin/') return '/';
    return raw;
}

function errorText(e: unknown): string {
    if (!(e instanceof ApiProblem)) return i18next.t('unreachableRetry', { ns: 'errors' });
    const fields = fieldErrors(e);
    return fields.email ?? fields.password ?? problemMessage(e);
}

export function SignInPage() {
    const { t } = useTranslation('auth');
    const [params] = useSearchParams();
    const next = safeNext(params.get('next'));
    const me = useMe();
    const queryClient = useQueryClient();

    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const sending = useRef(false);
    const locale = useLocale();

    // The error is in the language it arrived in; drop it when the language changes.
    useEffect(() => setError(null), [locale]);

    // A signed-in visitor, or one who just signed in, goes on to `next`.
    if (me.data) return <Navigate to={next} replace />;
    if (me.isPending) return <PageSpinner />;

    const submit = async (e: FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        if (sending.current) return;
        sending.current = true;
        setBusy(true);
        setError(null);
        try {
            const signedIn = await apiFetch<Me>('/auth/login', {
                method: 'POST',
                json: { email: email.trim(), password },
            });
            queryClient.setQueryData(queryKeys.me, signedIn);
        } catch (err) {
            setError(errorText(err));
            setBusy(false);
        } finally {
            sending.current = false;
        }
    };

    return (
        <AuthCard>
            {params.get('expired') === '1' && (
                <AuthBanner tone="warn">{t('signIn.expired')}</AuthBanner>
            )}
            <AuthHeading title={t('signIn.title')} subtitle={t('signIn.subtitle')} />
            <form noValidate onSubmit={(e) => void submit(e)}>
                <TextInput
                    className="mt-5"
                    label={t('signIn.email')}
                    type="email"
                    name="email"
                    autoComplete="username"
                    autoCapitalize="none"
                    spellCheck={false}
                    placeholder={t('signIn.emailPlaceholder')}
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    aria-invalid={error !== null || undefined}
                />
                <TextInput
                    className="mt-3.5"
                    label={t('signIn.password')}
                    type="password"
                    name="password"
                    autoComplete="current-password"
                    placeholder={t('signIn.passwordPlaceholder')}
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    aria-invalid={error !== null || undefined}
                />
                {error !== null && <AuthError>{error}</AuthError>}
                <Button type="submit" variant="primary" size="lg" busy={busy} className="mt-[18px] h-[46px] w-full">
                    {busy && <Spinner />}
                    {t('signIn.submit')}
                </Button>
            </form>
        </AuthCard>
    );
}
