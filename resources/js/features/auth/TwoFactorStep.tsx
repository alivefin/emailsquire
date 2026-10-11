import { useEffect, useId, useRef, useState, type FormEvent } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import i18next from 'i18next';
import { Trans, useTranslation } from 'react-i18next';
import { apiFetch } from '../../api/client';
import { ApiProblem, fieldErrors, problemMessage } from '../../api/problems';
import { Button } from '../../components/ui/Button';
import { cx } from '../../components/ui/cx';
import { useLocale } from '../../i18n';
import { sanitizeCode } from '../../lib/otp';
import { getAssertion, isWebAuthnSupported } from '../../lib/webauthn';
import type { Json, RequestJson } from '../../api/types';
import { AuthError, AuthHeading, Spinner } from './AuthCard';
import { ConsoleSessionStep, useConsoleSession } from './ConsoleSessionStep';
import { startConsoleSession } from './session';
import type { Me } from './useMe';

type Mode = 'code' | 'recovery' | 'passkey';

const BACK_ARROW = '←';

function errorText(e: unknown, field: string): string {
    if (!(e instanceof ApiProblem)) return i18next.t('unreachableRetry', { ns: 'errors' });
    return fieldErrors(e)[field] ?? problemMessage(e);
}

/**
 * The second sign-in step: an advertised authenticator app code or passkey, or a recovery code.
 * POST /auth/login (or a setup link) has checked the password and the session holds the
 * pending sign-in for five minutes. Success stores `me` and calls `onSignedIn`; the sign-in page then goes
 * on to `next`. The code lives only in this component's state.
 */
export function TwoFactorStep({
    email,
    onBack,
    onSignedIn,
    methods = [],
}: {
    email: string;
    onBack: () => void;
    onSignedIn?: (me: Me) => void;
    methods?: ('totp' | 'passkey' | 'recovery')[];
}) {
    const { t } = useTranslation('auth');
    const queryClient = useQueryClient();
    const consoleSession = useConsoleSession(onSignedIn);
    const locale = useLocale();
    const inputId = useId();
    const errorId = useId();
    const input = useRef<HTMLInputElement>(null);

    const hasTotp = methods.includes('totp');
    const hasPasskey = methods.includes('passkey') && isWebAuthnSupported();
    const [mode, setMode] = useState<Mode>(() => hasTotp ? 'code' : hasPasskey ? 'passkey' : 'recovery');
    const [value, setValue] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const sending = useRef(false);
    const ceremony = useRef<AbortController | null>(null);
    useEffect(() => () => ceremony.current?.abort(), []);

    // The error is in the language it arrived in; drop it when the language changes.
    useEffect(() => setError(null), [locale]);
    // Focus the field when the step appears and after switching between code and recovery code.
    useEffect(() => input.current?.focus(), [mode]);

    const field = mode === 'code' ? 'code' : 'recovery_code';
    const answer = mode === 'code' ? value : value.trim();

    const switchMode = () => {
        if (sending.current) return;
        setMode((m) => (m === 'recovery' && hasTotp ? 'code' : 'recovery'));
        setValue('');
        setError(null);
    };

    const signInWithPasskey = async () => {
        if (sending.current) return;
        sending.current = true;
        setBusy(true);
        setError(null);
        const controller = new AbortController();
        ceremony.current = controller;
        try {
            const options = await apiFetch<Json<'/auth/two-factor-challenge/passkey-options', 'post'>>('/auth/two-factor-challenge/passkey-options', { method: 'POST', signal: controller.signal });
            if (controller.signal.aborted) return;
            const passkey = await getAssertion(options, controller.signal);
            if (!passkey || controller.signal.aborted) return;
            await apiFetch<Json<'/auth/two-factor-challenge', 'post'>>('/auth/two-factor-challenge', { method: 'POST', json: { passkey } satisfies RequestJson<'/auth/two-factor-challenge', 'post'>, signal: controller.signal });
            if (controller.signal.aborted) return;
            await consoleSession.load();
        } catch (err) {
            if (!controller.signal.aborted) setError(errorText(err, 'passkey'));
        } finally {
            sending.current = false;
            setBusy(false);
        }
    };

    const submit = async (e: FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        if (answer === '' || sending.current) return;
        sending.current = true;
        setBusy(true);
        setError(null);
        try {
            const signedIn = await apiFetch<Me>('/auth/two-factor-challenge', {
                method: 'POST',
                json: { [field]: answer },
            });
            startConsoleSession(queryClient, signedIn);
            onSignedIn?.(signedIn);
        } catch (err) {
            setError(errorText(err, field));
            setBusy(false);
        } finally {
            sending.current = false;
        }
    };

    if (consoleSession.started) return <ConsoleSessionStep session={consoleSession} onBack={onBack} />;

    return (
        <>
            {mode === 'code' ? (
                <AuthHeading
                    title={t('twoFactor.title')}
                    subtitle={
                        <Trans
                            t={t}
                            i18nKey="twoFactor.subtitle"
                            values={{ email }}
                            components={[<b key="email" className="font-semibold text-ink" />]}
                        />
                    }
                />
            ) : mode === 'passkey' ? (
                <AuthHeading title={t('twoFactor.passkey')} />
            ) : (
                <AuthHeading title={t('twoFactor.recoveryTitle')} subtitle={t('twoFactor.recoverySubtitle')} />
            )}
            {mode !== 'passkey' && (
                <form noValidate onSubmit={(e) => void submit(e)}>
                    <label htmlFor={inputId} className={mode === 'code' ? 'sr-only' : 'mt-5 block text-[13px] font-semibold text-ink'}>
                        {mode === 'code' ? t('twoFactor.codeLabel') : t('twoFactor.recoveryLabel')}
                    </label>
                    <input
                        key={mode}
                        ref={input}
                        id={inputId}
                        name={field}
                        value={value}
                        onChange={(e) => {
                            setValue(mode === 'code' ? sanitizeCode(e.target.value) : e.target.value);
                            setError(null);
                        }}
                        autoComplete={mode === 'code' ? 'one-time-code' : 'off'}
                        inputMode={mode === 'code' ? 'numeric' : undefined}
                        placeholder={mode === 'code' ? t('twoFactor.codePlaceholder') : undefined}
                        autoCapitalize="none"
                        spellCheck={false}
                        aria-invalid={error !== null || undefined}
                        aria-describedby={error !== null ? errorId : undefined}
                        className={cx(
                            'w-full rounded-md border bg-white px-3 font-mono outline-none focus:border-teal focus:ring-2 focus:ring-teal/20',
                            error !== null ? 'border-danger' : 'border-line-strong',
                            mode === 'code'
                                ? 'mt-5 h-14 text-center text-[26px] tracking-[.3em] placeholder:text-faint max-md:h-[60px] max-md:text-[28px]'
                                : 'mt-1.5 h-11 text-sm max-md:h-12 max-md:text-base',
                        )}
                    />
                    {error !== null && <AuthError id={errorId}>{error}</AuthError>}
                    <Button type="submit" variant="primary" size="lg" busy={busy} className="mt-[18px] h-[46px] w-full">
                        {busy && <Spinner />}
                        {t('twoFactor.submit')}
                    </Button>
                </form>
            )}
            {mode === 'passkey' && error !== null && <AuthError id={errorId}>{error}</AuthError>}
            {hasPasskey && (
                <Button size="lg" variant={mode === 'passkey' ? 'primary' : 'secondary'} busy={busy} className="mt-3 w-full" onClick={() => void signInWithPasskey()}>
                    {t('twoFactor.passkey')}
                </Button>
            )}
            <div className="mt-3.5 flex flex-wrap justify-between gap-2 text-[12.5px] max-md:text-sm">
                <button type="button" onClick={onBack} className="font-semibold text-muted hover:text-ink">
                    <span aria-hidden>{BACK_ARROW} </span>
                    {t('twoFactor.back')}
                </button>
                {(mode !== 'recovery' || hasTotp) && (
                    <button type="button" disabled={busy} onClick={switchMode} className="font-semibold text-teal-dark hover:underline disabled:opacity-50">
                        {mode === 'recovery' ? t('twoFactor.useApp') : t('twoFactor.useRecovery')}
                    </button>
                )}
            </div>
        </>
    );
}
