import { useEffect, useId, useRef, useState, type FormEvent } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { useLocation } from 'react-router';
import { apiFetch } from '../../api/client';
import { ApiProblem, fieldErrors, isStepUpCancelled } from '../../api/problems';
import { queryKeys, type Json } from '../../api/types';
import { Button } from '../../components/ui/Button';
import { cx } from '../../components/ui/cx';
import { Dialog } from '../../components/ui/Dialog';
import { TextInput } from '../../components/ui/TextInput';
import { useToast } from '../../components/ui/Toast';
import { sanitizeCode } from '../../lib/otp';
import type { Me } from '../auth/useMe';
import { errorText } from '../domain/shared';
import { ProfileSection } from './ProfileSection';
import { RecoveryCodes } from './RecoveryCodes';

type Setup = Json<'/me/two-factor/setup', 'get'>;
type Codes = Json<'/me/recovery-codes', 'get'>;

/**
 * What the section shows besides its status. The secret, the QR code and the recovery codes live
 * here only, in this component's state: never in the query or mutation cache, storage, a URL, a toast
 * or a log. Leaving a view (Done, Cancel) or the page drops them.
 */
type View =
    | { kind: 'status' }
    | { kind: 'setup'; secret: string; qr: string }
    | { kind: 'codes'; codes: string[]; fresh: boolean };

type Busy = 'on' | 'confirm' | 'show' | 'regenerate' | 'off' | null;

/** The server sends the QR SVG base64-encoded; anything else is not put into the image URL. */
const BASE64 = /^[A-Za-z0-9+/]+={0,2}$/;

export const TWO_FACTOR_SECTION_ID = 'two-factor';
const LOW_CODES = 2;

/** An AbortSignal per mount, aborted on unmount (a fresh one after StrictMode's remount). */
function useMountedSignal(): () => AbortSignal | undefined {
    const controller = useRef<AbortController | null>(null);
    useEffect(() => {
        const c = new AbortController();
        controller.current = c;
        return () => c.abort();
    }, []);
    return () => controller.current?.signal;
}

export function TwoFactorSection({ me }: { me: Me }) {
    const { t } = useTranslation('profile');
    const queryClient = useQueryClient();
    const toast = useToast();
    const { hash } = useLocation();
    const mountedSignal = useMountedSignal();

    const [view, setView] = useState<View>({ kind: 'status' });
    const [busy, setBusy] = useState<Busy>(null);
    const [error, setError] = useState<string | undefined>();
    const [code, setCode] = useState('');
    const [codeError, setCodeError] = useState<string | undefined>();
    const [dialog, setDialog] = useState<'off' | 'regenerate' | null>(null);
    const [dialogError, setDialogError] = useState<string | undefined>();
    const sending = useRef(false);

    const section = useRef<HTMLElement>(null);
    const setupHeading = useRef<HTMLHeadingElement>(null);
    const focusSection = useRef(false);
    const setupHeadingId = useId();

    const confirmed = me.two_factor.confirmed;
    const left = me.two_factor.recovery_codes_left;

    // `#two-factor` (the required-2FA redirect, or a link) scrolls to this section and focuses it.
    useEffect(() => {
        if (hash !== `#${TWO_FACTOR_SECTION_ID}`) return;
        const el = section.current;
        if (!el) return;
        el.focus({ preventScroll: true });
        el.scrollIntoView?.({ block: 'start' });
    }, [hash]);

    useEffect(() => {
        if (view.kind === 'setup') setupHeading.current?.focus();
    }, [view.kind]);

    // After a view closes (Done, Cancel, turned off) focus goes back to the section, not to the page.
    useEffect(() => {
        if (!focusSection.current) return;
        focusSection.current = false;
        section.current?.focus();
    });

    const refreshMe = () => queryClient.invalidateQueries({ queryKey: queryKeys.me });

    /**
     * Runs one request at a time. A cancelled step-up is a quiet cancel; other failures go to
     * `onError`. Nothing is reported once the section has unmounted.
     */
    async function run(kind: Exclude<Busy, null>, work: (signal: AbortSignal | undefined) => Promise<void>, onError: (e: unknown) => void) {
        if (sending.current) return;
        sending.current = true;
        setBusy(kind);
        setError(undefined);
        const signal = mountedSignal();
        try {
            await work(signal);
        } catch (e) {
            if (!signal?.aborted && !isStepUpCancelled(e)) onError(e);
        } finally {
            sending.current = false;
            setBusy(null);
        }
    }

    const failed = (e: unknown) => setError(errorText(e, t('twoFactor.failed')));

    function turnOn() {
        void run(
            'on',
            async (signal) => {
                // A fresh secret each time; a setup left unfinished earlier is replaced.
                await apiFetch('/me/two-factor', { method: 'POST', signal });
                const setup = await apiFetch<Setup>('/me/two-factor/setup', { signal });
                if (signal?.aborted) return;
                setCode('');
                setCodeError(undefined);
                setView({ kind: 'setup', secret: setup.secret, qr: BASE64.test(setup.qr_svg_base64) ? setup.qr_svg_base64 : '' });
            },
            (e) => {
                // Confirmed meanwhile (another tab): show the real state.
                if (e instanceof ApiProblem && e.code === 'two_factor_already_confirmed') void refreshMe();
                failed(e);
            },
        );
    }

    function confirmCode(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        if (code.length !== 6) return;
        setCodeError(undefined);
        void run(
            'confirm',
            async (signal) => {
                // Aborted on unmount, the server may still confirm: "Show recovery codes" then shows the codes.
                const res = await apiFetch<Codes>('/me/two-factor/confirm', { method: 'POST', json: { code }, signal });
                if (signal?.aborted) return;
                setCode('');
                setView({ kind: 'codes', codes: res.recovery_codes, fresh: true });
                void refreshMe();
            },
            (err) => {
                if (err instanceof ApiProblem && (err.code === 'two_factor_already_confirmed' || err.code === 'two_factor_not_enabled')) {
                    // The setup this form belongs to is gone: leave it and show where things stand.
                    setView({ kind: 'status' });
                    void refreshMe();
                    failed(err);
                    return;
                }
                setCodeError(fieldErrors(err).code ?? errorText(err, t('twoFactor.failed')));
            },
        );
    }

    function cancelSetup() {
        // Local only: the secret leaves the screen and the server keeps the unconfirmed setup until the
        // next "Turn on" replaces it. Removing it would need step-up, a surprise dialog for a Cancel.
        setView({ kind: 'status' });
        setCode('');
        setCodeError(undefined);
        focusSection.current = true;
        void refreshMe();
    }

    function showCodes() {
        void run(
            'show',
            async (signal) => {
                const res = await apiFetch<Codes>('/me/recovery-codes', { signal });
                if (signal?.aborted) return;
                setView({ kind: 'codes', codes: res.recovery_codes, fresh: false });
            },
            failed,
        );
    }

    function regenerate() {
        setDialogError(undefined);
        void run(
            'regenerate',
            async (signal) => {
                // Aborted on unmount, the server may still regenerate: "Show recovery codes" then shows the new ones.
                const res = await apiFetch<Codes>('/me/recovery-codes', { method: 'POST', signal });
                if (signal?.aborted) return;
                setDialog(null);
                setView({ kind: 'codes', codes: res.recovery_codes, fresh: true });
                void refreshMe();
            },
            (e) => setDialogError(errorText(e, t('twoFactor.failed'))),
        );
    }

    function turnOff() {
        setDialogError(undefined);
        void run(
            'off',
            async (signal) => {
                await apiFetch('/me/two-factor', { method: 'DELETE', signal });
                setDialog(null);
                setView({ kind: 'status' });
                focusSection.current = true;
                toast.show({ kind: 'success', text: t('twoFactor.turnedOff') });
                await refreshMe();
            },
            (e) => setDialogError(errorText(e, t('twoFactor.failed'))),
        );
    }

    function closeCodes() {
        setView({ kind: 'status' });
        focusSection.current = true;
    }

    async function copySecret(secret: string) {
        try {
            await navigator.clipboard.writeText(secret);
            toast.show({ kind: 'success', text: t('twoFactor.secretCopied') });
        } catch {
            toast.show({ kind: 'danger', text: t('copyFailed') });
        }
    }

    const status = (
        <span
            className={cx(
                'rounded-full px-2.5 py-0.5 text-[12.5px] font-semibold',
                confirmed ? 'bg-tint text-teal-dark' : 'bg-subtle text-muted',
            )}
        >
            {confirmed ? t('twoFactor.on') : t('twoFactor.off')}
        </span>
    );

    return (
        <ProfileSection ref={section} id={TWO_FACTOR_SECTION_ID} focusable title={t('twoFactor.title')} aside={status}>
            {view.kind === 'setup' && (
                <div role="group" aria-labelledby={setupHeadingId}>
                    <h3 id={setupHeadingId} ref={setupHeading} tabIndex={-1} className="text-[15px] font-bold text-ink outline-none">
                        {t('twoFactor.setupTitle')}
                    </h3>
                    <p className="mt-1 text-[13px] text-body">{t('twoFactor.setupText')}</p>
                    <div className="mt-4 flex gap-5 max-sm:flex-col">
                        {view.qr !== '' && (
                            <img
                                src={`data:image/svg+xml;base64,${view.qr}`}
                                alt={t('twoFactor.qrAlt')}
                                width={168}
                                height={168}
                                className="size-[168px] flex-none rounded-md border border-line bg-white p-2"
                            />
                        )}
                        <div className="min-w-0 flex-1">
                            <div className="text-[13px] font-semibold text-ink">{t('twoFactor.secret')}</div>
                            <div className="mt-1.5 flex flex-wrap items-center gap-2">
                                <code className="min-w-0 rounded-md border border-line bg-subtle px-3 py-2 font-mono text-[13.5px] break-all text-ink">
                                    {view.secret}
                                </code>
                                <Button size="sm" onClick={() => void copySecret(view.secret)}>
                                    {t('twoFactor.copySecret')}
                                </Button>
                            </div>
                            <form noValidate onSubmit={confirmCode} className="mt-4">
                                <TextInput
                                    label={t('twoFactor.code')}
                                    hint={t('twoFactor.codeHint')}
                                    error={codeError}
                                    value={code}
                                    onChange={(e) => setCode(sanitizeCode(e.target.value))}
                                    inputMode="numeric"
                                    autoComplete="one-time-code"
                                    placeholder={t('twoFactor.codePlaceholder')}
                                    className="max-w-[260px]"
                                />
                                <div className="mt-3 flex flex-wrap gap-2">
                                    <Button type="submit" variant="primary" busy={busy === 'confirm'} disabled={code.length !== 6}>
                                        {t('twoFactor.confirm')}
                                    </Button>
                                    <Button onClick={cancelSetup} disabled={busy === 'confirm'}>
                                        {t('cancel')}
                                    </Button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            )}

            {view.kind === 'codes' && <RecoveryCodes codes={view.codes} fresh={view.fresh} onDone={closeCodes} />}

            {view.kind === 'status' && !confirmed && (
                <div>
                    <p className="text-[13.5px] text-body">{t('twoFactor.offText')}</p>
                    <Button variant="primary" className="mt-3" busy={busy === 'on'} onClick={turnOn}>
                        {t('twoFactor.turnOn')}
                    </Button>
                </div>
            )}

            {view.kind === 'status' && confirmed && (
                <div>
                    <p className="text-[13.5px] text-body">{t('twoFactor.onText')}</p>
                    <p className={cx('mt-2 text-[13.5px] font-semibold', left <= LOW_CODES ? 'text-warn' : 'text-ink')}>
                        {t('twoFactor.codesLeft', { count: left })}
                    </p>
                    {left <= LOW_CODES && (
                        <p className="mt-2 rounded-md border border-warn-line bg-warn-bg px-3 py-2 text-[13px] text-ink">
                            {t('twoFactor.codesLow')}
                        </p>
                    )}
                    <div className="mt-3 flex flex-wrap gap-2">
                        <Button busy={busy === 'show'} onClick={showCodes}>
                            {t('twoFactor.showCodes')}
                        </Button>
                        <Button
                            onClick={() => {
                                setDialogError(undefined);
                                setDialog('regenerate');
                            }}
                        >
                            {t('twoFactor.regenerate')}
                        </Button>
                        <Button
                            variant="danger"
                            onClick={() => {
                                setDialogError(undefined);
                                setDialog('off');
                            }}
                        >
                            {t('twoFactor.turnOff')}
                        </Button>
                    </div>
                </div>
            )}

            {error !== undefined && (
                <p role="alert" className="mt-3 text-[12.5px] text-danger">
                    {error}
                </p>
            )}

            <Dialog
                open={dialog === 'regenerate'}
                onClose={() => setDialog(null)}
                size="sm"
                title={t('twoFactor.regenerateTitle')}
                footer={
                    <>
                        <Button onClick={() => setDialog(null)}>{t('cancel')}</Button>
                        <Button variant="primary" busy={busy === 'regenerate'} onClick={regenerate}>
                            {t('twoFactor.regenerateConfirm')}
                        </Button>
                    </>
                }
            >
                <p>{t('twoFactor.regenerateBody')}</p>
                {dialogError !== undefined && (
                    <p role="alert" className="mt-3 text-[12.5px] text-danger">
                        {dialogError}
                    </p>
                )}
            </Dialog>

            <Dialog
                open={dialog === 'off'}
                onClose={() => setDialog(null)}
                size="sm"
                title={t('twoFactor.offTitle')}
                footer={
                    <>
                        <Button onClick={() => setDialog(null)}>{t('cancel')}</Button>
                        <Button variant="danger" busy={busy === 'off'} onClick={turnOff}>
                            {t('twoFactor.offConfirm')}
                        </Button>
                    </>
                }
            >
                <p>{t('twoFactor.offBody')}</p>
                {me.two_factor_required && <p className="mt-2 font-semibold text-ink">{t('twoFactor.offRequired')}</p>}
                {dialogError !== undefined && (
                    <p role="alert" className="mt-3 text-[12.5px] text-danger">
                        {dialogError}
                    </p>
                )}
            </Dialog>
        </ProfileSection>
    );
}
