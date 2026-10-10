import { useEffect, useId, useRef, useState, type FormEvent } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import i18next from 'i18next';
import { Trans, useTranslation } from 'react-i18next';
import { apiFetch } from '../../api/client';
import { ApiProblem, fieldErrors, problemMessage } from '../../api/problems';
import { queryKeys } from '../../api/types';
import { Button } from '../../components/ui/Button';
import { cx } from '../../components/ui/cx';
import { useLocale } from '../../i18n';
import { sanitizeCode } from '../../lib/otp';
import { AuthError, AuthHeading, Spinner } from './AuthCard';
import type { Me } from './useMe';

type Mode = 'code' | 'recovery';

const BACK_ARROW = '←';

function errorText(e: unknown, field: string): string {
    if (!(e instanceof ApiProblem)) return i18next.t('unreachableRetry', { ns: 'errors' });
    return fieldErrors(e)[field] ?? problemMessage(e);
}

/**
 * The second sign-in step (prototype lines 31–38): the 6-digit code from the authenticator app, or one
 * recovery code. POST /auth/login has checked the password and the session holds the pending sign-in for
 * five minutes. Success stores `me`, and the sign-in page then goes on to `next`. The code lives only in
 * this component's state.
 */
export function TwoFactorStep({ email, onBack }: { email: string; onBack: () => void }) {
    const { t } = useTranslation('auth');
    const queryClient = useQueryClient();
    const locale = useLocale();
    const inputId = useId();
    const errorId = useId();
    const input = useRef<HTMLInputElement>(null);

    const [mode, setMode] = useState<Mode>('code');
    const [value, setValue] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const sending = useRef(false);

    // The error is in the language it arrived in; drop it when the language changes.
    useEffect(() => setError(null), [locale]);
    // Focus the field when the step appears and after switching between code and recovery code.
    useEffect(() => input.current?.focus(), [mode]);

    const field = mode === 'code' ? 'code' : 'recovery_code';
    const answer = mode === 'code' ? value : value.trim();

    const switchMode = () => {
        setMode((m) => (m === 'code' ? 'recovery' : 'code'));
        setValue('');
        setError(null);
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
            queryClient.setQueryData(queryKeys.me, signedIn);
        } catch (err) {
            setError(errorText(err, field));
            setBusy(false);
        } finally {
            sending.current = false;
        }
    };

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
            ) : (
                <AuthHeading title={t('twoFactor.recoveryTitle')} subtitle={t('twoFactor.recoverySubtitle')} />
            )}
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
            <div className="mt-3.5 flex flex-wrap justify-between gap-2 text-[12.5px] max-md:text-sm">
                <button type="button" onClick={onBack} className="font-semibold text-muted hover:text-ink">
                    <span aria-hidden>{BACK_ARROW} </span>
                    {t('twoFactor.back')}
                </button>
                <button type="button" onClick={switchMode} className="font-semibold text-teal-dark hover:underline">
                    {mode === 'code' ? t('twoFactor.useRecovery') : t('twoFactor.useApp')}
                </button>
            </div>
        </>
    );
}
