import { useEffect, useId, useRef, useState, type FormEvent } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Link, Navigate, useLocation, useNavigate, useSearchParams } from 'react-router';
import { apiFetch } from '../../api/client';
import i18next from 'i18next';
import { ApiProblem, fieldErrors, problemMessage } from '../../api/problems';
import type { Json } from '../../api/types';
import { Button } from '../../components/ui/Button';
import { labelClass } from '../../components/ui/fieldStyles';
import { TextInput } from '../../components/ui/TextInput';
import { useLocale } from '../../i18n';
import { PageSpinner } from '../../layout/PageSpinner';
import { getAssertion, isWebAuthnSupported } from '../../lib/webauthn';
import { usePortalMe } from '../portal/usePortal';
import { AuthBanner, AuthCard, AuthError, AuthHeading, Spinner } from './AuthCard';
import { ConsoleSessionStep, useConsoleSession } from './ConsoleSessionStep';
import { afterPortalSignInPath, afterSignInPath } from './landing';
import { startConsoleSession, startPortalSession } from './session';
import { TwoFactorStep } from './TwoFactorStep';
import { useMe, type Me } from './useMe';

export { safeNext } from './landing';

/** Router state the reset page leaves for the "password changed" banner. */
export interface SignInState {
    passwordChanged?: boolean;
}

/** POST /auth/login answers this instead of `me` when the user must pass the two-factor step next. */
type PendingSignIn = Extract<Json<'/auth/login', 'post'>, { two_factor: true }>;

function isTwoFactorPending(value: unknown): value is PendingSignIn {
    return typeof value === 'object' && value !== null && (value as Record<string, unknown>).two_factor === true;
}

/** POST /auth/login answers `{kind: 'mailbox'}` to a mailbox owner: a portal session, not a console one. */
function isMailbox(value: unknown): boolean {
    return typeof value === 'object' && value !== null && (value as Record<string, unknown>).kind === 'mailbox';
}

/** A console sign-in's answer as GET /me gives it: the same payload without `kind`. */
function asMe(answer: Json<'/auth/login', 'post'>): Me {
    const me: Record<string, unknown> = { ...answer };
    delete me.kind;
    return me as Me;
}

function errorText(e: unknown): string {
    if (!(e instanceof ApiProblem)) return i18next.t('unreachableRetry', { ns: 'errors' });
    const fields = fieldErrors(e);
    return fields.email ?? fields.password ?? fields.credential ?? problemMessage(e);
}

export function SignInPage() {
    const { t } = useTranslation('auth');
    const [params] = useSearchParams();
    const location = useLocation();
    const navigate = useNavigate();
    // Shown until the next sign-in attempt; the router state that asked for it is cleared at once,
    // so a reload or a later visit does not show it again.
    const [passwordChanged, setPasswordChanged] = useState(
        () => (location.state as SignInState | null)?.passwordChanged === true,
    );
    const rawNext = params.get('next');
    const passwordId = useId();
    const mailboxHintId = useId();
    const me = useMe();
    // Asked alongside `me`, not after it: a visitor with a portal session goes back to the portal.
    const portal = usePortalMe();
    const queryClient = useQueryClient();
    const consoleSession = useConsoleSession();

    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    // "I want to manage my mailbox": skips the console account (someone whose console and mail passwords
    // are the same). Kept while this page is open, so a refused attempt doesn't drop it.
    const [mailboxOnly, setMailboxOnly] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [codeStep, setCodeStep] = useState(false);
    const [methods, setMethods] = useState<PendingSignIn['methods']>([]);
    const sending = useRef(false);
    const ceremony = useRef<AbortController | null>(null);
    useEffect(() => () => ceremony.current?.abort(), []);
    const backFromCode = useRef(false);
    const locale = useLocale();

    // The error is in the language it arrived in; drop it when the language changes.
    useEffect(() => setError(null), [locale]);

    useEffect(() => {
        if ((location.state as SignInState | null)?.passwordChanged !== true) return;
        void navigate(
            { pathname: location.pathname, search: location.search, hash: location.hash },
            { replace: true, state: null },
        );
    }, [location, navigate]);

    // "Back to sign in" from the code step replaces the focused control: put focus on the password.
    useEffect(() => {
        if (codeStep || !backFromCode.current) return;
        backFromCode.current = false;
        document.getElementById(passwordId)?.focus();
    }, [codeStep, passwordId]);

    // A signed-in visitor, or one who just signed in, goes on to `next` (or their only domain).
    if (me.data) return <Navigate to={afterSignInPath(me.data, rawNext)} replace />;
    if (portal.data) return <Navigate to={afterPortalSignInPath(rawNext)} replace />;
    if (me.isPending || portal.isPending) return <PageSpinner />;

    const submit = async (e: FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        if (sending.current) return;
        sending.current = true;
        setBusy(true);
        setError(null);
        setPasswordChanged(false);
        try {
            const signedIn = await apiFetch<Json<'/auth/login', 'post'>>('/auth/login', {
                method: 'POST',
                json: mailboxOnly ? { email: email.trim(), password, mailbox: true } : { email: email.trim(), password },
            });
            if (isTwoFactorPending(signedIn)) {
                // The password has done its job; the code step needs only the address.
                setPassword('');
                setMethods(signedIn.methods ?? []);
                setBusy(false);
                setCodeStep(true);
            } else if (isMailbox(signedIn)) {
                // A mailbox owner's portal lives under /my; the console's `me` stays signed out. Nothing an
                // earlier session cached (another mailbox's forwarding, say) survives the switch.
                startPortalSession(queryClient);
                void navigate(afterPortalSignInPath(rawNext), { replace: true });
            } else {
                // Signing in to the console signed out any portal session in this browser.
                startConsoleSession(queryClient, asMe(signedIn));
            }
        } catch (err) {
            setError(errorText(err));
            setBusy(false);
        } finally {
            sending.current = false;
        }
    };

    const signInWithPasskey = async () => {
        if (sending.current) return;
        sending.current = true;
        setBusy(true);
        setError(null);
        setPasswordChanged(false);
        const controller = new AbortController();
        ceremony.current = controller;
        try {
            const options = await apiFetch<Json<'/auth/passkey/options', 'post'>>('/auth/passkey/options', { method: 'POST', signal: controller.signal });
            if (controller.signal.aborted) return;
            const credential = await getAssertion(options, controller.signal);
            if (!credential || controller.signal.aborted) return;
            await apiFetch<Json<'/auth/passkey', 'post'>>('/auth/passkey', {
                method: 'POST', json: { credential }, signal: controller.signal,
            });
            if (controller.signal.aborted) return;
            // The passkey response schema omits /me's capabilities. Drop the earlier session before
            // reading the canonical profile, so even a failed read cannot preserve another account.
            await consoleSession.load();
        } catch (err) {
            if (!controller.signal.aborted) setError(errorText(err));
        } finally {
            sending.current = false;
            setBusy(false);
        }
    };

    if (codeStep) {
        return (
            <AuthCard>
                <TwoFactorStep
                    email={email.trim()}
                    methods={methods}
                    onBack={() => {
                        setError(null);
                        backFromCode.current = true;
                        setCodeStep(false);
                    }}
                />
            </AuthCard>
        );
    }

    if (consoleSession.started) {
        return <AuthCard><ConsoleSessionStep session={consoleSession} onBack={consoleSession.reset} /></AuthCard>;
    }

    return (
        <AuthCard>
            {params.get('expired') === '1' && (
                <AuthBanner tone="warn">{t('signIn.expired')}</AuthBanner>
            )}
            {passwordChanged && <AuthBanner tone="success">{t('signIn.passwordChanged')}</AuthBanner>}
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
                <div className="mt-3.5 flex items-baseline justify-between gap-3">
                    <label htmlFor={passwordId} className={labelClass}>
                        {t('signIn.password')}
                    </label>
                    <Link to="/forgot-password" className="text-[12.5px] font-semibold text-teal-dark hover:underline max-md:text-sm">
                        {t('signIn.forgot')}
                    </Link>
                </div>
                <TextInput
                    id={passwordId}
                    type="password"
                    name="password"
                    autoComplete="current-password"
                    placeholder={t('signIn.passwordPlaceholder')}
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    aria-invalid={error !== null || undefined}
                />
                <div className="mt-3.5">
                    <label className="flex min-h-11 cursor-pointer items-center gap-2.5 text-[13px] font-semibold text-ink md:min-h-0 max-md:text-sm">
                        <input
                            type="checkbox"
                            name="mailbox"
                            checked={mailboxOnly}
                            onChange={(e) => setMailboxOnly(e.target.checked)}
                            aria-describedby={mailboxHintId}
                            className="size-4 flex-none accent-teal"
                        />
                        {t('signIn.mailboxOnly')}
                    </label>
                    <p id={mailboxHintId} className="ml-[26px] text-[12.5px] text-muted max-md:text-sm">
                        {t('signIn.mailboxOnlyHint')}
                    </p>
                </div>
                {error !== null && <AuthError>{error}</AuthError>}
                <Button type="submit" variant="primary" size="lg" busy={busy} className="mt-[18px] h-[46px] w-full">
                    {busy && <Spinner />}
                    {t('signIn.submit')}
                </Button>
            </form>
            {isWebAuthnSupported() && (
                <Button size="lg" busy={busy} className="mt-3 w-full" onClick={() => void signInWithPasskey()}>
                    {t('signIn.passkey')}
                </Button>
            )}
            {/* "Forgot password?" mails a reset link, which a locked-out mailbox could never receive. */}
            <p className="mt-4 text-[12.5px] text-muted max-md:text-sm">{t('signIn.mailboxForgot')}</p>
        </AuthCard>
    );
}
