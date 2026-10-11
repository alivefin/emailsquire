import { useCallback, useEffect, useId, useRef, useState, type FormEvent } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { apiFetch, onStepUpRequired } from '../api/client';
import { ApiProblem, fieldErrors, problemMessage } from '../api/problems';
import { queryKeys, type Json } from '../api/types';
import type { Me } from '../features/auth/useMe';
import { sanitizeCode } from '../lib/otp';
import { getAssertion, isWebAuthnSupported } from '../lib/webauthn';
import { Button } from './ui/Button';
import { Dialog } from './ui/Dialog';
import { TextInput } from './ui/TextInput';

type Mode = 'code' | 'password';

/**
 * "Confirm it's you": the step-up dialog. Mounted once; apiFetch asks it through onStepUpRequired when a
 * request answers 423 `step_up_required`, and retries that request once if the user confirms. Parallel
 * 423s share one pending confirmation (the client keeps it), so there is never more than one dialog.
 *
 * The password or code stays in the form's own state, which unmounts when the dialog closes.
 */
export function StepUpDialog() {
    const [open, setOpen] = useState(false);
    const resolver = useRef<((confirmed: boolean) => void) | null>(null);

    useEffect(() => {
        const off = onStepUpRequired(
            (signal) =>
                new Promise<boolean>((resolve) => {
                    // The client never asks twice at once; settle a stale request rather than leak it.
                    resolver.current?.(false);
                    resolver.current = resolve;
                    setOpen(true);
                    // Every request waiting for this confirmation was aborted: nothing is left to retry.
                    signal.addEventListener(
                        'abort',
                        () => {
                            if (resolver.current !== resolve) return;
                            resolver.current = null;
                            setOpen(false);
                            resolve(false);
                        },
                        { once: true },
                    );
                }),
        );
        return () => {
            off();
            resolver.current?.(false);
            resolver.current = null;
        };
    }, []);

    const finish = useCallback((confirmed: boolean) => {
        const resolve = resolver.current;
        resolver.current = null;
        setOpen(false);
        resolve?.(confirmed);
    }, []);

    return open ? <StepUpForm onDone={finish} /> : null;
}

function StepUpForm({ onDone }: { onDone: (confirmed: boolean) => void }) {
    const { t } = useTranslation(['common', 'errors']);
    const queryClient = useQueryClient();
    // Read once when the dialog opens, from the cache: no /me request of its own, and the mode choice
    // does not change under the user while the dialog is open.
    const [hasCode] = useState(() => queryClient.getQueryData<Me | null>(queryKeys.me)?.two_factor?.confirmed === true);
    const [hasPasskey] = useState(() => isWebAuthnSupported() && (queryClient.getQueryData<Me | null>(queryKeys.me)?.passkeys ?? 0) > 0);

    const formId = useId();
    const fieldId = useId();
    const hintId = useId();
    const [mode, setMode] = useState<Mode>(hasCode ? 'code' : 'password');
    const [password, setPassword] = useState('');
    const [code, setCode] = useState('');
    const [error, setError] = useState<string | undefined>();
    const [passkeyError, setPasskeyError] = useState<string | undefined>();
    const [busy, setBusy] = useState(false);
    const sending = useRef(false);
    const toggled = useRef(false);
    const ceremony = useRef<AbortController | null>(null);
    useEffect(() => () => ceremony.current?.abort(), []);

    // The dialog focuses the field when it opens; after a mode switch, focus the new field.
    useEffect(() => {
        if (toggled.current) document.getElementById(fieldId)?.focus();
    }, [mode, fieldId]);

    const ready = mode === 'code' ? code.length === 6 : password !== '';

    const switchMode = () => {
        if (sending.current) return;
        toggled.current = true;
        setError(undefined);
        setPasskeyError(undefined);
        setMode((m) => (m === 'code' ? 'password' : 'code'));
    };

    const submit = async (e?: FormEvent) => {
        e?.preventDefault();
        if (!ready || sending.current) return;
        sending.current = true;
        setBusy(true);
        setError(undefined);
        setPasskeyError(undefined);
        try {
            await apiFetch<undefined>('/auth/confirm-password', {
                method: 'POST',
                json: mode === 'code' ? { code } : { password },
            });
            onDone(true);
        } catch (err) {
            if (err instanceof ApiProblem) {
                const fields = fieldErrors(err);
                setError(fields[mode] ?? problemMessage(err));
            } else {
                setError(t('errors:unreachableRetry'));
            }
            setBusy(false);
        } finally {
            sending.current = false;
        }
    };

    const confirmWithPasskey = async () => {
        if (sending.current) return;
        sending.current = true;
        setBusy(true);
        setError(undefined);
        setPasskeyError(undefined);
        const controller = new AbortController();
        ceremony.current = controller;
        try {
            const options = await apiFetch<Json<'/auth/confirm-password/passkey-options', 'post'>>('/auth/confirm-password/passkey-options', {
                method: 'POST', signal: controller.signal,
            });
            if (controller.signal.aborted) return;
            const passkey = await getAssertion(options, controller.signal);
            if (!passkey || controller.signal.aborted) return;
            await apiFetch('/auth/confirm-password', { method: 'POST', json: { passkey }, signal: controller.signal });
            if (!controller.signal.aborted) onDone(true);
        } catch (err) {
            if (!controller.signal.aborted) setPasskeyError(err instanceof ApiProblem
                ? fieldErrors(err).passkey ?? problemMessage(err) : t('errors:unreachableRetry'));
        } finally {
            sending.current = false;
            setBusy(false);
        }
    };

    return (
        <Dialog
            open
            size="sm"
            onClose={() => onDone(false)}
            title={t('stepUp.title')}
            subtitle={mode === 'code' ? t('stepUp.codeSubtitle') : t('stepUp.passwordSubtitle')}
            footer={
                <>
                    <Button onClick={() => onDone(false)}>{t('stepUp.cancel')}</Button>
                    <Button type="submit" form={formId} variant="primary" busy={busy} disabled={!ready}>
                        {t('stepUp.confirm')}
                    </Button>
                </>
            }
        >
            <form id={formId} noValidate onSubmit={(e) => void submit(e)}>
                {mode === 'code' ? (
                    <>
                        <TextInput
                            key="code"
                            id={fieldId}
                            label={t('stepUp.code')}
                            name="code"
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            spellCheck={false}
                            placeholder={t('stepUp.codePlaceholder')}
                            value={code}
                            onChange={(e) => {
                                setCode(sanitizeCode(e.target.value));
                                setError(undefined);
                            }}
                            error={error}
                            aria-describedby={error ? `${fieldId}-msg ${hintId}` : hintId}
                            className="[&_input]:text-center [&_input]:font-mono [&_input]:tracking-[.3em]"
                        />
                        <p id={hintId} className="mt-1 text-[12.5px] text-muted">
                            {t('stepUp.codeHint')}
                        </p>
                    </>
                ) : (
                    <TextInput
                        key="password"
                        id={fieldId}
                        label={t('stepUp.password')}
                        type="password"
                        name="password"
                        autoComplete="current-password"
                        value={password}
                        onChange={(e) => {
                            setPassword(e.target.value);
                            setError(undefined);
                        }}
                        error={error}
                    />
                )}
                {hasCode && (
                    <Button variant="link" size="sm" className="mt-2" onClick={switchMode} disabled={busy}>
                        {mode === 'code' ? t('stepUp.usePassword') : t('stepUp.useCode')}
                    </Button>
                )}
                {hasPasskey && (
                    <Button className="mt-3 w-full" busy={busy} onClick={() => void confirmWithPasskey()}>
                        {t('stepUp.passkey')}
                    </Button>
                )}
                {passkeyError !== undefined && <p role="alert" className="mt-2 text-[12.5px] text-danger">{passkeyError}</p>}
            </form>
        </Dialog>
    );
}
