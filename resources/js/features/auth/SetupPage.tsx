import { useId, useRef, useState, type FormEvent } from 'react';
import { useNavigate, useParams, useSearchParams } from 'react-router';
import { apiFetch } from '../../api/client';
import { ApiProblem, fieldErrors, problemMessage } from '../../api/problems';
import { Button } from '../../components/ui/Button';
import { PasswordField } from '../../components/ui/PasswordField';
import { TextInput } from '../../components/ui/TextInput';
import { scorePassword } from '../../lib/passwordStrength';
import { AuthBanner, AuthCard, AuthHeading, Spinner } from './AuthCard';

type Errors = Partial<Record<'token' | 'email' | 'password' | 'password_confirmation' | 'form', string>>;

function toErrors(e: unknown): Errors {
    if (!(e instanceof ApiProblem)) return { form: 'The server could not be reached. Try again.' };
    const fields = fieldErrors(e);
    if (Object.keys(fields).length === 0) return { form: problemMessage(e) };
    return fields;
}

/** `/setup/:token?email=`: the one-time link from `emailsquire:create-admin`. */
export function SetupPage() {
    const { token = '' } = useParams();
    const [params] = useSearchParams();
    const email = params.get('email') ?? '';
    const navigate = useNavigate();
    const passwordMsgId = useId();

    const [password, setPassword] = useState('');
    const [confirmation, setConfirmation] = useState('');
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Errors>({});
    const sending = useRef(false);

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
            await apiFetch('/auth/setup', {
                method: 'POST',
                json: { token, email, password, password_confirmation: confirmation },
            });
            navigate('/signin?setup=1', { replace: true });
        } catch (err) {
            setErrors(toErrors(err));
            setBusy(false);
        } finally {
            sending.current = false;
        }
    };

    const linkProblem = errors.token ?? errors.form ?? (email === '' ? 'This setup link is missing the email address.' : null);

    return (
        <AuthCard>
            {linkProblem !== null && <AuthBanner tone="danger">{linkProblem}</AuthBanner>}
            <AuthHeading title="Set your password" subtitle="You will use it to sign in to EmailSquire." />
            <form noValidate onSubmit={(e) => void submit(e)}>
                <TextInput className="mt-5" label="Email" type="email" value={email} readOnly error={errors.email} />
                <PasswordField
                    className="mt-3.5"
                    label="New password"
                    value={password}
                    onChange={setPassword}
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
                    label="Confirm password"
                    type="password"
                    autoComplete="new-password"
                    value={confirmation}
                    onChange={(e) => setConfirmation(e.target.value)}
                    error={errors.password_confirmation ?? (mismatch ? 'The passwords do not match.' : undefined)}
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
                    Set password
                </Button>
            </form>
        </AuthCard>
    );
}
