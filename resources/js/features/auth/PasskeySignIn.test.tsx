import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { apiFetch } from '../../api/client';
import { ApiProblem } from '../../api/problems';
import { queryKeys } from '../../api/types';
import { setLocale } from '../../i18n';
import { renderRoutes } from '../../test/routing';
import { ASSERTION, ASSERTION_JSON, REQUEST_OPTIONS, mockWebAuthn } from '../../test/webauthn';
import { SignInPage } from './SignInPage';
import { SetupPage } from './SetupPage';

vi.mock('../../api/client', () => ({
    apiFetch: vi.fn(), onSessionExpired: vi.fn(() => () => {}), onTwoFactorRequired: vi.fn(() => () => {}),
    onStepUpRequired: vi.fn(() => () => {}), ensureCsrf: vi.fn(),
}));
const ME = {
    id: 1, name: 'Anna', email: 'anna@example.org', is_server_admin: true, locale: 'en', second_factor: true,
    passkeys: 1, two_factor: { enabled: false, confirmed: false, recovery_codes_left: 8 }, two_factor_required: true,
    capabilities: { schema_extension: { receiving: true, sendonly: true }, catch_all: true },
};
const routes = [{ path: '/signin', element: <SignInPage /> }, { path: '/setup/:token', element: <SetupPage /> }];
let credentials: ReturnType<typeof mockWebAuthn>;
let methods: string[];
let refusal: ApiProblem | undefined;
let authenticated: boolean;
let meFailure: boolean;
beforeEach(() => {
    credentials = mockWebAuthn(); methods = ['passkey', 'recovery']; refusal = undefined; authenticated = false; meFailure = false;
    vi.mocked(apiFetch).mockReset().mockImplementation((path) => {
        if (path === '/me' && authenticated) return meFailure ? Promise.reject(new Error('offline')) : Promise.resolve(ME);
        if (path === '/me' || path === '/portal/me') return Promise.reject(new ApiProblem({ status: 401, code: 'unauthenticated', title: 'T' }));
        if (path.endsWith('/options') || path.endsWith('/passkey-options')) return Promise.resolve(REQUEST_OPTIONS);
        if (path === '/auth/login' || path === '/auth/setup') return Promise.resolve({ two_factor: true, methods });
        if (path === '/auth/passkey' || path === '/auth/two-factor-challenge') {
            if (refusal) return Promise.reject(refusal);
            authenticated = true;
            const answer = { ...ME }; delete (answer as Partial<typeof answer>).capabilities;
            return Promise.resolve(path === '/auth/passkey' ? { kind: 'console', ...answer } : answer);
        }
        return Promise.reject(new Error(`unexpected ${path}`));
    });
});
afterEach(() => vi.unstubAllGlobals());
async function passwordStep() {
    const utils = renderRoutes(routes, '/signin?next=%2Fprofile');
    const user = userEvent.setup();
    await user.type(await screen.findByLabelText('Email'), ME.email);
    await user.type(screen.getByLabelText('Password'), 'summersun-long');
    await user.click(screen.getByRole('button', { name: 'Continue' }));
    await screen.findByLabelText('Two-factor code');
    return { ...utils, user };
}

describe('passkey sign-in', () => {
    it('signs in without an email or password and clears the earlier session caches', async () => {
        const { client } = renderRoutes(routes, '/signin?next=%2Fprofile');
        client.setQueryData(['old-session'], { private: true });
        client.setQueryData(queryKeys.passkeys, { data: [{ name: 'Previous user phone' }] });
        await userEvent.click(await screen.findByRole('button', { name: 'Sign in with a passkey' }));
        expect(await screen.findByTestId('location')).toHaveTextContent('/profile');
        expect(apiFetch).toHaveBeenCalledWith('/auth/passkey/options', expect.objectContaining({ method: 'POST' }));
        expect(apiFetch).toHaveBeenCalledWith('/auth/passkey', expect.objectContaining({ method: 'POST', json: { credential: ASSERTION_JSON } }));
        expect(client.getQueryData(queryKeys.me)).toEqual(ME);
        expect(vi.mocked(apiFetch).mock.calls.filter(([path]) => path === '/me')).toHaveLength(2);
        expect(client.getQueryData(['old-session'])).toBeUndefined();
        expect(client.getQueryData(queryKeys.passkeys)).toBeUndefined();
        expect(client.getQueryData(queryKeys.portalMe)).toBeNull();
    });
    it('clears the earlier session when canonical me fails after successful passkey sign-in', async () => {
        meFailure = true;
        const { client } = renderRoutes(routes, '/signin');
        client.setQueryData(['old-session'], { private: true });
        client.setQueryData(queryKeys.passkeys, { data: [{ name: 'Previous user phone' }] });
        await userEvent.click(await screen.findByRole('button', { name: 'Sign in with a passkey' }));
        expect(await screen.findByRole('alert')).toHaveTextContent('The server could not be reached. Try again.');
        expect(client.getQueryData(['old-session'])).toBeUndefined();
        expect(client.getQueryData(queryKeys.passkeys)).toBeUndefined();
        expect(client.getQueryData(queryKeys.me)).toBeNull();
        expect(client.getQueryData(queryKeys.portalMe)).toBeNull();
        meFailure = false;
        await userEvent.click(screen.getByRole('button', { name: 'Try again' }));
        expect(await screen.findByTestId('location')).toHaveTextContent('/');
        expect(credentials.get).toHaveBeenCalledOnce();
        expect(vi.mocked(apiFetch).mock.calls.filter(([path]) => path === '/auth/passkey')).toHaveLength(1);
        expect(vi.mocked(apiFetch).mock.calls.filter(([path]) => path === '/me')).toHaveLength(3);
    });
    it('aborts a pending browser prompt on navigation and posts no late assertion', async () => {
        let resolve: (value: typeof ASSERTION) => void = () => {};
        credentials.get.mockImplementation(() => new Promise((done) => { resolve = done; }));
        const { router } = renderRoutes(routes, '/signin');
        await userEvent.click(await screen.findByRole('button', { name: 'Sign in with a passkey' }));
        await waitFor(() => expect(credentials.get).toHaveBeenCalledOnce());
        await act(() => router.navigate('/elsewhere'));
        expect(credentials.get.mock.calls[0]![0].signal.aborted).toBe(true);
        await act(() => resolve(ASSERTION));
        expect(apiFetch).not.toHaveBeenCalledWith('/auth/passkey', expect.anything());
    });
    it.each(['NotAllowedError', 'AbortError'])('keeps cancellation %s quiet and the password form usable', async (name) => {
        credentials.get.mockRejectedValue(new DOMException('', name));
        renderRoutes(routes, '/signin');
        const button = await screen.findByRole('button', { name: 'Sign in with a passkey' });
        await userEvent.click(button);
        await waitFor(() => expect(button).toBeEnabled());
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Continue' })).toBeEnabled();
        expect(apiFetch).not.toHaveBeenCalledWith('/auth/passkey', expect.anything());
        await userEvent.type(screen.getByLabelText('Password'), 'retry');
        expect(screen.getByLabelText('Password')).toHaveValue('retry');
    });
    it('hides the button when unsupported', async () => {
        vi.stubGlobal('PublicKeyCredential', undefined);
        renderRoutes(routes, '/signin');
        await screen.findByLabelText('Email');
        expect(screen.queryByRole('button', { name: 'Sign in with a passkey' })).not.toBeInTheDocument();
    });
    it.each(['passkey_failed', 'passkey_cloned', 'too_many_requests'])('shows %s and permits retry', async (code) => {
        refusal = new ApiProblem({ status: code === 'too_many_requests' ? 429 : 422, code, title: 'T', errors: { credential: ['Try another passkey.'] } });
        renderRoutes(routes, '/signin');
        const button = await screen.findByRole('button', { name: 'Sign in with a passkey' });
        await userEvent.click(button);
        expect(await screen.findByRole('alert')).toHaveTextContent('Try another passkey.');
        expect(button).toBeEnabled();
    });
    it('renders the passkey sign-in button in Finnish', async () => {
        await setLocale('fi'); renderRoutes(routes, '/signin');
        expect(await screen.findByRole('button', { name: 'Kirjaudu pääsyavaimella' })).toBeInTheDocument();
    });
});
describe('passkey two-factor step', () => {
    it('uses the advertised passkey method and keeps code and recovery inputs available', async () => {
        const { user, client } = await passwordStep();
        expect(screen.getByRole('button', { name: 'Use a recovery code' })).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Use your passkey' }));
        expect(await screen.findByTestId('location')).toHaveTextContent('/profile');
        expect(apiFetch).toHaveBeenCalledWith('/auth/two-factor-challenge/passkey-options', expect.objectContaining({ method: 'POST' }));
        expect(apiFetch).toHaveBeenCalledWith('/auth/two-factor-challenge', expect.objectContaining({ json: { passkey: ASSERTION_JSON } }));
        expect(client.getQueryData(queryKeys.me)).toEqual(ME);
    });
    it('hides the button when methods do not include passkey', async () => {
        methods = ['totp', 'recovery']; await passwordStep();
        expect(screen.queryByRole('button', { name: 'Use your passkey' })).not.toBeInTheDocument();
    });
    it('retries only the canonical profile after a consumed passkey challenge', async () => {
        meFailure = true;
        const { user, client } = await passwordStep();
        client.setQueryData(['old-session'], { private: true });
        await user.click(screen.getByRole('button', { name: 'Use your passkey' }));
        expect(await screen.findByRole('alert')).toHaveTextContent('The server could not be reached. Try again.');
        expect(client.getQueryData(['old-session'])).toBeUndefined();
        expect(screen.queryByLabelText('Two-factor code')).not.toBeInTheDocument();
        meFailure = false;
        await user.click(screen.getByRole('button', { name: 'Try again' }));
        expect(await screen.findByTestId('location')).toHaveTextContent('/profile');
        expect(credentials.get).toHaveBeenCalledOnce();
        expect(vi.mocked(apiFetch).mock.calls.filter(([path]) => path === '/auth/two-factor-challenge')).toHaveLength(1);
        expect(vi.mocked(apiFetch).mock.calls.filter(([path]) => path === '/me')).toHaveLength(3);
        expect(client.getQueryData(queryKeys.me)).toEqual(ME);
    });
    it('renders the canonical-profile retry state in Finnish', async () => {
        meFailure = true;
        const { user } = await passwordStep();
        await user.click(screen.getByRole('button', { name: 'Use your passkey' }));
        await screen.findByRole('alert');
        await act(() => setLocale('fi'));
        expect(screen.getByRole('heading', { name: 'Olet kirjautunut sisään' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Yritä uudelleen' })).toBeEnabled();
    });
    it('hides the button when unsupported even when passkey is advertised', async () => {
        vi.stubGlobal('PublicKeyCredential', undefined); await passwordStep();
        expect(screen.queryByRole('button', { name: 'Use your passkey' })).not.toBeInTheDocument();
    });
    it('keeps a cancelled prompt quiet and the code form usable', async () => {
        credentials.get.mockRejectedValue(new DOMException('', 'NotAllowedError'));
        const { user } = await passwordStep();
        const button = screen.getByRole('button', { name: 'Use your passkey' });
        await user.click(button); await waitFor(() => expect(button).toBeEnabled());
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
        expect(apiFetch).not.toHaveBeenCalledWith('/auth/two-factor-challenge', expect.anything());
        await user.type(screen.getByLabelText('Two-factor code'), '123456');
        expect(screen.getByLabelText('Two-factor code')).toHaveValue('123456');
    });
    it('shows a failed passkey assertion and leaves recovery available', async () => {
        refusal = new ApiProblem({ status: 422, code: 'passkey_failed', title: 'T', errors: { passkey: ['Try another method.'] } });
        const { user } = await passwordStep();
        await user.click(screen.getByRole('button', { name: 'Use your passkey' }));
        expect(await screen.findByRole('alert')).toHaveTextContent('Try another method.');
        await user.click(screen.getByRole('button', { name: 'Use a recovery code' }));
        expect(screen.getByLabelText('Recovery code')).toBeEnabled();
    });
    it('renders the passkey challenge button in Finnish', async () => {
        const { user } = await passwordStep();
        await user.click(screen.getByRole('button', { name: 'Use a recovery code' }));
        await setLocale('fi');
        expect(await screen.findByRole('button', { name: 'Käytä pääsyavaintasi' })).toBeInTheDocument();
    });
    it('forwards the methods from a setup-link sign-in', async () => {
        renderRoutes(routes, '/setup/token?email=anna%40example.org');
        const user = userEvent.setup();
        await user.type(screen.getByLabelText('New password'), 'Correct-Horse-Battery-9');
        await user.type(screen.getByLabelText('Confirm password'), 'Correct-Horse-Battery-9');
        await user.click(screen.getByRole('button', { name: 'Set password' }));
        expect(await screen.findByRole('button', { name: 'Use your passkey' })).toBeInTheDocument();
    });
});
