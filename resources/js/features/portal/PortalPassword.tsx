import { useEffect, useId, useRef, useState, type FormEvent } from 'react';
import i18next from 'i18next';
import { useTranslation } from 'react-i18next';
import { apiFetch } from '../../api/client';
import { ApiProblem, fieldErrors, problemMessage } from '../../api/problems';
import { Button } from '../../components/ui/Button';
import { PasswordField } from '../../components/ui/PasswordField';
import { TextInput } from '../../components/ui/TextInput';
import { useLocale } from '../../i18n';
import { scorePassword } from '../../lib/passwordStrength';
import { PortalCard, PortalPage } from './PortalCard';

type Errors = Partial<Record<'current_password' | 'password' | 'password_confirmation' | 'form', string>>;

/** The mailbox rules (backend plan §5.2): 12 characters to 72 bytes, as bcrypt reads only 72. */
const MIN_CHARACTERS = 12;
const MAX_BYTES = 72;

const encoder = new TextEncoder();

function toErrors(e: unknown): Errors {
    if (!(e instanceof ApiProblem)) return { form: i18next.t('unreachableRetry', { ns: 'errors' }) };
    const fields = fieldErrors(e);
    // 429 and 503 (`password_check_unavailable`) carry no field: they show under the form.
    if (Object.keys(fields).length === 0) return { form: problemMessage(e) };
    return fields;
}

const CHECK_MARK = '✓';

/**
 * `/my/password`: the mailbox's own password. The rules come before the fields, the new one has the
 * strength meter and show/hide (no Generate: the owner must remember it). The server checks the current
 * password with the mail server, signs the mailbox's other mail apps and portal sessions out, and keeps
 * this one. A 401 means the session ended meanwhile (an administrator changed the password, say): the
 * portal's guard then goes to sign-in.
 */
export function PortalPassword() {
    const { t } = useTranslation('portal');
    const locale = useLocale();
    const newMsgId = useId();
    const rulesId = useId();
    const [current, setCurrent] = useState('');
    const [password, setPassword] = useState('');
    const [confirmation, setConfirmation] = useState('');
    const [errors, setErrors] = useState<Errors>({});
    const [changed, setChanged] = useState(false);
    const [busy, setBusy] = useState(false);
    const sending = useRef(false);

    // Server errors are in the language they arrived in; drop them when the language changes.
    useEffect(() => setErrors({}), [locale]);

    const tooLong = encoder.encode(password).length > MAX_BYTES;
    const mismatch = confirmation !== '' && confirmation.length >= password.length && confirmation !== password;
    const ready =
        current !== '' &&
        !tooLong &&
        [...password].length >= MIN_CHARACTERS &&
        scorePassword(password) >= 3 &&
        confirmation === password;
    const newError = errors.password ?? (tooLong ? t('password.tooLong') : undefined);

    async function submit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        if (!ready || sending.current) return;
        sending.current = true;
        setBusy(true);
        setErrors({});
        setChanged(false);
        try {
            await apiFetch('/portal/password', {
                method: 'PUT',
                json: { current_password: current, password, password_confirmation: confirmation },
            });
            setCurrent('');
            setPassword('');
            setConfirmation('');
            setChanged(true);
        } catch (err) {
            // A 401 has already ended the session; the guard is on its way to sign-in.
            if (!(err instanceof ApiProblem && err.status === 401)) setErrors(toErrors(err));
        } finally {
            sending.current = false;
            setBusy(false);
        }
    }

    return (
        <PortalPage title={t('password.title')}>
            <PortalCard subtitle={t('password.subtitle')}>
                {changed && (
                    <div
                        role="status"
                        className="mb-4 flex gap-2.5 rounded-md border border-teal/30 bg-tint px-3 py-2.5 text-[13.5px] text-body max-md:text-sm"
                    >
                        <span aria-hidden="true" className="font-extrabold text-teal-dark">
                            {CHECK_MARK}
                        </span>
                        <span>{t('password.changed')}</span>
                    </div>
                )}
                <p id={rulesId} className="text-[13px] font-semibold text-ink max-md:text-sm">
                    {t('password.rulesTitle')}
                </p>
                <ul aria-labelledby={rulesId} className="mt-1 list-disc pl-5 text-[13px] text-body max-md:text-sm">
                    <li>{t('password.rules.length')}</li>
                    <li>{t('password.rules.mix')}</li>
                    <li>{t('password.rules.max')}</li>
                </ul>
                <p className="mt-2 text-[13px] text-muted max-md:text-sm">{t('password.afterChange')}</p>
                <form noValidate onSubmit={(e) => void submit(e)} className="mt-4 flex max-w-[460px] flex-col gap-3.5">
                    <TextInput
                        label={t('password.current')}
                        type="password"
                        name="current-password"
                        autoComplete="current-password"
                        value={current}
                        onChange={(e) => setCurrent(e.target.value)}
                        error={errors.current_password}
                    />
                    <div>
                        <PasswordField
                            label={t('password.new')}
                            value={password}
                            onChange={setPassword}
                            allowGenerate={false}
                            aria-invalid={newError ? true : undefined}
                            aria-describedby={newError ? newMsgId : undefined}
                        />
                        {newError && (
                            <p id={newMsgId} role="alert" className="mt-1 text-[12.5px] text-danger">
                                {newError}
                            </p>
                        )}
                    </div>
                    <TextInput
                        label={t('password.confirm')}
                        type="password"
                        autoComplete="new-password"
                        value={confirmation}
                        onChange={(e) => setConfirmation(e.target.value)}
                        error={errors.password_confirmation ?? (mismatch ? t('password.mismatch') : undefined)}
                    />
                    {errors.form !== undefined && (
                        <p role="alert" className="text-[12.5px] text-danger">
                            {errors.form}
                        </p>
                    )}
                    <div>
                        <Button type="submit" variant="primary" size="lg" busy={busy} disabled={!ready} className="max-md:w-full">
                            {t('password.submit')}
                        </Button>
                    </div>
                </form>
            </PortalCard>
        </PortalPage>
    );
}
