import { useEffect, useRef, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import i18next from 'i18next';
import { useTranslation } from 'react-i18next';
import { Button } from '../../components/ui/Button';
import { useLocale } from '../../i18n';
import { errorText } from '../domain/shared';
import { AuthError, AuthHeading, Spinner } from './AuthCard';
import { loadConsoleSession } from './session';
import type { Me } from './useMe';

/** Authentication has finished. Retry only the profile read, never a spent two-factor challenge. */
export function useConsoleSession(onSignedIn?: (me: Me) => void) {
    const queryClient = useQueryClient();
    const locale = useLocale();
    const [started, setStarted] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const controller = useRef<AbortController | null>(null);
    const sending = useRef(false);
    useEffect(() => () => controller.current?.abort(), []);
    useEffect(() => setError(null), [locale]);

    async function load() {
        if (sending.current) return;
        sending.current = true;
        setStarted(true);
        setBusy(true);
        setError(null);
        const ceremony = new AbortController();
        controller.current = ceremony;
        try {
            const me = await loadConsoleSession(queryClient, ceremony.signal);
            if (me) onSignedIn?.(me);
        } catch (err) {
            if (!ceremony.signal.aborted) setError(errorText(err, i18next.t('errors:unreachableRetry')));
        } finally {
            sending.current = false;
            setBusy(false);
        }
    }

    function reset() {
        controller.current?.abort();
        setStarted(false);
        setError(null);
    }

    return { started, busy, error, load, reset };
}

export function ConsoleSessionStep({ session, onBack }: { session: ReturnType<typeof useConsoleSession>; onBack: () => void }) {
    const { t } = useTranslation('auth');
    return (
        <>
            <AuthHeading title={t('session.title')} subtitle={session.busy ? t('session.loading') : t('session.retryText')} />
            {session.error !== null && <AuthError>{session.error}</AuthError>}
            <Button size="lg" variant="primary" busy={session.busy} className="mt-[18px] w-full" onClick={() => void session.load()}>
                {session.busy && <Spinner />}
                {t('session.retry')}
            </Button>
            <Button className="mt-3" variant="link" disabled={session.busy} onClick={onBack}>{t('twoFactor.back')}</Button>
        </>
    );
}
