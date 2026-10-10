import { useEffect, useId, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import i18next from 'i18next';
import { useTranslation } from 'react-i18next';
import { Link, useLocation, useNavigate } from 'react-router';
import { apiFetch } from '../../api/client';
import { ApiProblem, fieldErrors, problemMessage } from '../../api/problems';
import { queryKeys, type Json } from '../../api/types';
import { Button } from '../../components/ui/Button';
import { PasswordField } from '../../components/ui/PasswordField';
import { TextInput } from '../../components/ui/TextInput';
import { useToast } from '../../components/ui/Toast';
import { useLocale } from '../../i18n';
import { scorePassword } from '../../lib/passwordStrength';
import { AuthBanner, AuthCard, AuthHeading, Spinner } from './AuthCard';
import { afterSignInPath } from './landing';
import { useMe, type Me } from './useMe';

type Invitation = Json<'/auth/invitations/lookup', 'post'>;
type Closed = Exclude<Invitation['state'], 'valid'>;

/**
 * Where the token waits while its holder signs in (`/signin?next=/invite` and back), and only then: the
 * one place it is kept outside this page's state. Session storage is per tab and gone with it; the
 * page removes the token once accepted, once the invitation turns out closed, and whenever it is left
 * other than to sign in.
 */
export const INVITATION_TOKEN_KEY = 'emailsquire.invitation';

function storedToken(): string {
    try {
        return window.sessionStorage.getItem(INVITATION_TOKEN_KEY) ?? '';
    } catch {
        return '';
    }
}

function storeToken(token: string | null): void {
    try {
        if (token === null) window.sessionStorage.removeItem(INVITATION_TOKEN_KEY);
        else window.sessionStorage.setItem(INVITATION_TOKEN_KEY, token);
    } catch {
        // Storage blocked: the holder opens the link again after signing in.
    }
}

type View =
    | { kind: 'loading' }
    /** No token, or one the server doesn't know. */
    | { kind: 'invalid' }
    | { kind: 'failed'; error: unknown }
    | { kind: 'ready'; invitation: Invitation };

function isClosed(state: string | null): state is Closed {
    return state === 'expired' || state === 'used' || state === 'revoked';
}

/** How an accept that failed ends: the invitation is gone, or an error for the form to show. */
type AcceptFailure = { gone: Closed } | { invalid: true } | { errors: Record<string, string> };

function acceptFailure(e: unknown): AcceptFailure {
    if (!(e instanceof ApiProblem)) return { errors: { form: i18next.t('unreachableRetry', { ns: 'errors' }) } };
    if (e.status === 410) return { gone: isClosed(e.state) ? e.state : 'revoked' };
    if (e.status === 404) return { invalid: true };
    const fields = fieldErrors(e);
    return { errors: Object.keys(fields).length > 0 ? fields : { form: problemMessage(e) } };
}

/**
 * `/invite#{token}`: the emailed invitation link. The token sits in the fragment, so no server sees it
 * in a URL; the page moves it into its own state, takes it out of the address bar at once, and sends it
 * only in POST bodies. It never goes into a query key, a toast, a log or a `next=` parameter.
 */
export function InvitationPage() {
    const location = useLocation();
    const navigate = useNavigate();
    const [token] = useState(() => {
        const fromLink = location.hash.replace(/^#/, '');
        return fromLink !== '' ? fromLink : storedToken();
    });
    const [view, setView] = useState<View>(() => (token === '' ? { kind: 'invalid' } : { kind: 'loading' }));
    const [attempt, setAttempt] = useState(0);
    const handingOff = useRef(false);

    // Out of the address bar and the history entry: replace `/invite#…` with plain `/invite`.
    useEffect(() => {
        if (location.hash !== '') void navigate({ pathname: location.pathname, search: location.search }, { replace: true });
    }, [location.hash, location.pathname, location.search, navigate]);

    // Leaving the page forgets the token, unless it is on its way to the sign-in page and back.
    useEffect(
        () => () => {
            if (!handingOff.current) storeToken(null);
        },
        [],
    );

    useEffect(() => {
        if (token === '') return;
        let live = true;
        apiFetch<Invitation>('/auth/invitations/lookup', { method: 'POST', json: { token } }).then(
            (invitation) => {
                if (!live) return;
                if (invitation.state !== 'valid') storeToken(null);
                setView({ kind: 'ready', invitation });
            },
            (e: unknown) => {
                if (!live) return;
                if (e instanceof ApiProblem && e.status === 404) {
                    storeToken(null);
                    setView({ kind: 'invalid' });
                } else {
                    setView({ kind: 'failed', error: e });
                }
            },
        );
        return () => {
            live = false;
        };
    }, [token, attempt]);

    function closed(state: Closed) {
        storeToken(null);
        setView((v) => (v.kind === 'ready' ? { kind: 'ready', invitation: { ...v.invitation, state } } : v));
    }

    function invalid() {
        storeToken(null);
        setView({ kind: 'invalid' });
    }

    if (view.kind === 'invalid') return <ClosedCard state="invalid" />;
    if (view.kind === 'failed') {
        return (
            <LoadFailed
                error={view.error}
                onRetry={() => {
                    setView({ kind: 'loading' });
                    setAttempt((n) => n + 1);
                }}
            />
        );
    }
    if (view.kind === 'loading') return <Loading />;
    if (isClosed(view.invitation.state)) return <ClosedCard state={view.invitation.state} />;
    return (
        <ValidInvitation
            token={token}
            invitation={view.invitation}
            onClosed={closed}
            onInvalid={invalid}
            onHandOff={() => {
                handingOff.current = true;
                storeToken(token);
            }}
        />
    );
}

function Loading() {
    const { t } = useTranslation('auth');
    return (
        <AuthCard>
            <p aria-busy="true" className="mt-6 text-[13px] text-muted">
                {t('invitation.loading')}
            </p>
        </AuthCard>
    );
}

function LoadFailed({ error, onRetry }: { error: unknown; onRetry: () => void }) {
    const { t } = useTranslation('auth');
    return (
        <AuthCard>
            <AuthBanner tone="danger">
                {error instanceof ApiProblem ? problemMessage(error) : t('invitation.loadFailed')}
            </AuthBanner>
            <Button className="mt-4" onClick={onRetry}>
                {t('invitation.retry')}
            </Button>
        </AuthCard>
    );
}

const linkButtonClass =
    'mt-[18px] inline-flex h-[46px] w-full items-center justify-center rounded-md bg-teal px-4 text-sm font-semibold text-white ' +
    'hover:bg-teal-dark focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal max-md:h-[50px] max-md:text-[15px]';

function ClosedCard({ state }: { state: Closed | 'invalid' }) {
    const { t } = useTranslation('auth');
    return (
        <AuthCard>
            <AuthHeading title={t(`invitation.states.${state}.title`)} subtitle={t(`invitation.states.${state}.body`)} />
            <Link to="/signin" className={linkButtonClass}>
                {t('invitation.toSignIn')}
            </Link>
        </AuthCard>
    );
}

interface ValidProps {
    token: string;
    invitation: Invitation;
    onClosed: (state: Closed) => void;
    onInvalid: () => void;
    onHandOff: () => void;
}

function ValidInvitation({ token, invitation, onClosed, onInvalid, onHandOff }: ValidProps) {
    const { t } = useTranslation('auth');
    const me = useMe();

    if (me.isPending) return <Loading />;

    const signedIn = me.data ?? null;
    const own = signedIn !== null && signedIn.email.toLowerCase() === invitation.email.toLowerCase();

    let action: ReactNode;
    if (own) {
        action = <AcceptAsMe token={token} invitation={invitation} me={signedIn} onClosed={onClosed} onInvalid={onInvalid} />;
    } else if (signedIn !== null) {
        action = <WrongAccount me={signedIn} invitation={invitation} />;
    } else if (invitation.existing_user) {
        action = (
            <>
                <p className="mt-5 text-[13.5px] text-body wrap-anywhere">{t('invitation.signInPrompt', { email: invitation.email })}</p>
                {/* `next` names the page only: the token waits in session storage, never in the URL. */}
                <Link to="/signin?next=/invite" onClick={onHandOff} className={linkButtonClass}>
                    {t('invitation.signIn')}
                </Link>
            </>
        );
    } else {
        action = <NewPersonForm token={token} invitation={invitation} onClosed={onClosed} onInvalid={onInvalid} />;
    }

    return (
        <AuthCard>
            <AuthHeading title={t('invitation.title')} subtitle={t('invitation.subtitle')} />
            <Details invitation={invitation} />
            {action}
        </AuthCard>
    );
}

function Details({ invitation }: { invitation: Invitation }) {
    const { t } = useTranslation('auth');
    const term = 'text-muted';
    const value = 'min-w-0 font-semibold wrap-anywhere text-ink';
    return (
        <>
            <dl className="mt-5 grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-1.5 text-[13.5px]">
                {invitation.inviter_name !== null && (
                    <>
                        <dt className={term}>{t('invitation.invitedBy')}</dt>
                        <dd className={value}>{invitation.inviter_name}</dd>
                    </>
                )}
                <dt className={term}>{t('invitation.access')}</dt>
                <dd className={value}>{invitation.domain_unicode ?? invitation.domain ?? t('invitation.wholeServer')}</dd>
                <dt className={term}>{t('invitation.role')}</dt>
                <dd className={value}>{t(`invitation.roles.${invitation.role}`)}</dd>
            </dl>
            {invitation.note !== null && invitation.note !== '' && (
                <div className="mt-4 rounded-md border border-line bg-subtle px-3 py-2.5">
                    <div className="text-xs font-semibold text-muted">{t('invitation.note')}</div>
                    <p className="mt-0.5 text-[13px] whitespace-pre-line wrap-anywhere text-body">{invitation.note}</p>
                </div>
            )}
        </>
    );
}

interface AcceptProps {
    token: string;
    invitation: Invitation;
    onClosed: (state: Closed) => void;
    onInvalid: () => void;
}

function useAcceptErrors(): [Record<string, string>, (e: Record<string, string>) => void] {
    const [errors, setErrors] = useState<Record<string, string>>({});
    const locale = useLocale();
    // Server errors are in the language they arrived in; drop them when the language changes.
    useEffect(() => setErrors({}), [locale]);
    return [errors, setErrors];
}

/** An existing user, signed in as the invited email: one click adds the access. */
function AcceptAsMe({ token, invitation, me, onClosed, onInvalid }: AcceptProps & { me: Me }) {
    const { t } = useTranslation('auth');
    const queryClient = useQueryClient();
    const navigate = useNavigate();
    const toast = useToast();
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useAcceptErrors();
    const sending = useRef(false);

    async function accept() {
        if (sending.current) return;
        sending.current = true;
        setBusy(true);
        setErrors({});
        try {
            const updated = await apiFetch<Me>('/auth/invitations/accept', { method: 'POST', json: { token } });
            storeToken(null);
            queryClient.setQueryData(queryKeys.me, updated);
            // The new domain shows up in every list, the pins and the dashboard.
            await Promise.all([
                queryClient.invalidateQueries({ queryKey: ['domains'] }),
                queryClient.invalidateQueries({ queryKey: queryKeys.pinned }),
                queryClient.invalidateQueries({ queryKey: queryKeys.dashboard }),
                queryClient.invalidateQueries({ queryKey: ['addresses'] }),
            ]);
            toast.show({ kind: 'success', text: t('invitation.accepted') });
            // The domain they were invited to (a checked ASCII name), else wherever a sign-in would go.
            const to = invitation.domain !== null ? afterSignInPath({ landing: invitation.domain }) : afterSignInPath(updated);
            void navigate(to, { replace: true });
        } catch (e) {
            const failure = acceptFailure(e);
            setBusy(false);
            if ('gone' in failure) onClosed(failure.gone);
            else if ('invalid' in failure) onInvalid();
            else {
                setErrors(failure.errors);
                // The session may have ended, or changed account, since the page loaded.
                if (e instanceof ApiProblem && e.status === 409) void queryClient.invalidateQueries({ queryKey: queryKeys.me });
            }
        } finally {
            sending.current = false;
        }
    }

    return (
        <>
            <p className="mt-5 text-[13.5px] text-body wrap-anywhere">{t('invitation.acceptPrompt', { email: me.email })}</p>
            {errors.form !== undefined && <AuthBanner tone="danger">{errors.form}</AuthBanner>}
            <Button variant="primary" size="lg" busy={busy} onClick={() => void accept()} className="mt-[18px] h-[46px] w-full">
                {busy && <Spinner />}
                {t('invitation.accept')}
            </Button>
        </>
    );
}

/** Signed in as someone other than the invited email: only signing out helps. */
function WrongAccount({ me, invitation }: { me: Me; invitation: Invitation }) {
    const { t } = useTranslation('auth');
    const queryClient = useQueryClient();
    const [busy, setBusy] = useState(false);

    async function signOut() {
        setBusy(true);
        try {
            await apiFetch('/auth/logout', { method: 'POST' });
        } catch {
            // Whatever the outcome, this page goes on as signed out.
        }
        // Nothing of the old account stays cached; the page then offers the invitation's own way in.
        queryClient.removeQueries({ predicate: (q) => q.queryKey[0] !== queryKeys.me[0] });
        queryClient.setQueryData(queryKeys.me, null);
    }

    return (
        <>
            <AuthBanner tone="warn">
                <span className="block wrap-anywhere">{t('invitation.signedInAs', { email: me.email })}</span>
                <span className="mt-1 block wrap-anywhere">{t('invitation.wrongAccount', { invited: invitation.email })}</span>
            </AuthBanner>
            <Button size="lg" busy={busy} onClick={() => void signOut()} className="mt-[18px] h-[46px] w-full">
                {t('invitation.signOut')}
            </Button>
        </>
    );
}

/** A new person: they choose their name and password, and accepting signs them in. */
function NewPersonForm({ token, invitation, onClosed, onInvalid }: AcceptProps) {
    const { t } = useTranslation('auth');
    const queryClient = useQueryClient();
    const navigate = useNavigate();
    const toast = useToast();
    const passwordMsgId = useId();

    const [name, setName] = useState(invitation.name ?? '');
    const [password, setPassword] = useState('');
    const [confirmation, setConfirmation] = useState('');
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useAcceptErrors();
    const sending = useRef(false);

    const mismatch = confirmation !== '' && confirmation.length >= password.length && confirmation !== password;
    const ready = name.trim() !== '' && scorePassword(password) >= 3 && [...password].length >= 12 && confirmation === password;

    async function submit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        if (!ready || sending.current) return;
        sending.current = true;
        setBusy(true);
        setErrors({});
        try {
            const signedIn = await apiFetch<Me>('/auth/invitations/accept', {
                method: 'POST',
                json: { token, name: name.trim(), password, password_confirmation: confirmation },
            });
            storeToken(null);
            setPassword('');
            setConfirmation('');
            queryClient.setQueryData(queryKeys.me, signedIn);
            toast.show({ kind: 'success', text: t('invitation.welcome') });
            void navigate(afterSignInPath(signedIn), { replace: true });
        } catch (err) {
            const failure = acceptFailure(err);
            setBusy(false);
            if ('gone' in failure) onClosed(failure.gone);
            else if ('invalid' in failure) onInvalid();
            else {
                setErrors(failure.errors);
                if (err instanceof ApiProblem && err.status === 409) void queryClient.invalidateQueries({ queryKey: queryKeys.me });
            }
        } finally {
            sending.current = false;
        }
    }

    // A field the form doesn't have (say, `token`) still has to be seen.
    const { name: nameError, password: passwordError, password_confirmation: confirmError, form, ...other } = errors;
    const formError = form ?? Object.values(other)[0];

    return (
        <form noValidate onSubmit={(e) => void submit(e)}>
            {formError !== undefined && <AuthBanner tone="danger">{formError}</AuthBanner>}
            <TextInput className="mt-5" label={t('invitation.email')} type="email" value={invitation.email} readOnly />
            <TextInput
                className="mt-3.5"
                label={t('invitation.name')}
                autoComplete="name"
                maxLength={100}
                value={name}
                onChange={(e) => setName(e.target.value)}
                error={nameError}
            />
            <PasswordField
                className="mt-3.5"
                label={t('invitation.password')}
                value={password}
                onChange={setPassword}
                allowGenerate={false}
                aria-invalid={passwordError ? true : undefined}
                aria-describedby={passwordError ? passwordMsgId : undefined}
            />
            {passwordError && (
                <p id={passwordMsgId} role="alert" className="mt-1 text-[12.5px] text-danger">
                    {passwordError}
                </p>
            )}
            <TextInput
                className="mt-3.5"
                label={t('invitation.confirm')}
                type="password"
                autoComplete="new-password"
                value={confirmation}
                onChange={(e) => setConfirmation(e.target.value)}
                error={confirmError ?? (mismatch ? t('invitation.mismatch') : undefined)}
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
                {t('invitation.submit')}
            </Button>
        </form>
    );
}
