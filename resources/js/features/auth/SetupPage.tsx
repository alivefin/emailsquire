import { useEffect, useId, useRef, useState, type FormEvent } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import i18next from 'i18next';
import { useTranslation } from 'react-i18next';
import { useNavigate, useParams, useSearchParams } from 'react-router';
import { apiFetch } from '../../api/client';
import { ApiProblem, fieldErrors, problemMessage } from '../../api/problems';
import { queryKeys } from '../../api/types';
import { Button } from '../../components/ui/Button';
import { PasswordField } from '../../components/ui/PasswordField';
import { TextInput } from '../../components/ui/TextInput';
import { useToast } from '../../components/ui/Toast';
import { useLocale } from '../../i18n';
import { scorePassword } from '../../lib/passwordStrength';
import { AuthBanner, AuthCard, AuthHeading, Spinner } from './AuthCard';
import { TwoFactorStep } from './TwoFactorStep';
import { fetchMe, type Me } from './useMe';

type Errors = Partial<Record<'token' | 'email' | 'password' | 'password_confirmation' | 'form', string>>;

function toErrors(e: unknown): Errors {
    if (!(e instanceof ApiProblem)) return { form: i18next.t('unreachableRetry', { ns: 'errors' }) };
    const fields = fieldErrors(e);
    if (Object.keys(fields).length === 0) return { form: problemMessage(e) };
    return fields;
}

/** The link set the password, but the user has confirmed two-factor: the code step comes next. */
function isTwoFactorPending(value: unknown): boolean {
    return typeof value === 'object' && value !== null && (value as Record<string, unknown>).two_factor === true;
}

function isMe(value: unknown): value is Me {
    if (typeof value !== 'object' || value === null) return false;
    const v = value as Record<string, unknown>;
    return typeof v.id === 'number' && typeof v.email === 'string' && typeof v.is_server_admin === 'boolean';
}

/**
 * `/setup/:token?email=`: the one-time link from `emailsquire:create-admin`.
 * The server signs the admin in on success, so the page stores them as `me` and opens the console. A user
 * with confirmed two-factor (a `--reset` link) gets `{two_factor: true}` instead and enters a code first.
 */
export function SetupPage() {
    const { t } = useTranslation('auth');
    const { token = '' } = useParams();
    const [params] = useSearchParams();
    const email = params.get('email') ?? '';
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const toast = useToast();
    const passwordMsgId = useId();

    const [password, setPassword] = useState('');
    const [confirmation, setConfirmation] = useState('');
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Errors>({});
    const [codeStep, setCodeStep] = useState(false);
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
            const signedIn = await apiFetch<unknown>('/auth/setup', {
                method: 'POST',
                json: { token, email, password, password_confirmation: confirmation },
            });
            if (isTwoFactorPending(signedIn)) {
                // The password is set; the code step needs only the address.
                setPassword('');
                setConfirmation('');
                setBusy(false);
                setCodeStep(true);
                return;
            }
            if (isMe(signedIn)) {
                queryClient.setQueryData(queryKeys.me, signedIn);
            } else {
                // The password is set either way; if this fails, the guard on / retries /me itself.
                await queryClient
                    .fetchQuery({ queryKey: queryKeys.me, queryFn: fetchMe, staleTime: 0 })
                    .catch(() => undefined);
            }
            done();
        } catch (err) {
            setErrors(toErrors(err));
            setBusy(false);
        } finally {
            sending.current = false;
        }
    };

    function done() {
        toast.show({ kind: 'success', text: t('setup.success') });
        void navigate('/', { replace: true });
    }

    if (codeStep) {
        return (
            <AuthCard>
                <TwoFactorStep email={email} onBack={() => void navigate('/signin')} onSignedIn={done} />
            </AuthCard>
        );
    }

    const linkProblem = errors.token ?? errors.form ?? (email === '' ? t('setup.missingEmail') : null);

    return (
        <AuthCard>
            {linkProblem !== null && <AuthBanner tone="danger">{linkProblem}</AuthBanner>}
            <AuthHeading title={t('setup.title')} subtitle={t('setup.subtitle')} />
            <form noValidate onSubmit={(e) => void submit(e)}>
                <TextInput className="mt-5" label={t('setup.email')} type="email" value={email} readOnly error={errors.email} />
                <PasswordField
                    className="mt-3.5"
                    label={t('setup.newPassword')}
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
                    label={t('setup.confirm')}
                    type="password"
                    autoComplete="new-password"
                    value={confirmation}
                    onChange={(e) => setConfirmation(e.target.value)}
                    error={errors.password_confirmation ?? (mismatch ? t('setup.mismatch') : undefined)}
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
                    {t('setup.submit')}
                </Button>
            </form>
        </AuthCard>
    );
}
