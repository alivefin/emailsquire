import { beforeEach, describe, expect, it, vi } from 'vitest';
import { act, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { apiFetch } from '../../api/client';
import { ApiProblem } from '../../api/problems';
import { queryKeys } from '../../api/types';
import { setLocale } from '../../i18n';
import { renderRoutes } from '../../test/routing';
import { SignInPage } from './SignInPage';

vi.mock('../../api/client', () => ({
    apiFetch: vi.fn(),
    onSessionExpired: vi.fn(() => () => {}),
    onTwoFactorRequired: vi.fn(() => () => {}), onStepUpRequired: vi.fn(() => () => {}),
    ensureCsrf: vi.fn(),
}));

const ME = {
    id: 1,
    name: 'Anna Admin',
    email: 'anna@example.org',
    is_server_admin: true,
    locale: 'en',
    two_factor: { enabled: true, confirmed: true, recovery_codes_left: 7 },
    two_factor_required: true,
};
const unauthenticated = new ApiProblem({ status: 401, title: 'Unauthorized', code: 'unauthenticated' });
const invalid = (field: string, message: string) =>
    new ApiProblem({ status: 422, title: 'Unprocessable Content', code: 'validation_failed', errors: { [field]: [message] } });

const routes = [{ path: '/signin', element: <SignInPage /> }];

type Challenge = (body: Record<string, string>) => Promise<unknown>;

function mockApi(challenge: Challenge) {
    vi.mocked(apiFetch).mockImplementation((path: string, init) => {
        if (path === '/me') return Promise.reject(unauthenticated);
        if (path === '/auth/login') return Promise.resolve({ two_factor: true });
        if (path === '/auth/two-factor-challenge') return challenge(init?.json as Record<string, string>);
        return Promise.reject(new Error(`unexpected ${path}`));
    });
}

async function passPassword(path = '/signin') {
    const utils = renderRoutes(routes, path);
    const user = userEvent.setup();
    await user.type(await screen.findByLabelText('Email'), 'anna@example.org');
    await user.type(screen.getByLabelText('Password'), 'correct horse battery');
    await user.click(screen.getByRole('button', { name: 'Continue' }));
    await screen.findByRole('heading', { name: 'Enter your code' });
    return { ...utils, user };
}

beforeEach(() => {
    vi.mocked(apiFetch).mockReset();
});

describe('TwoFactorStep', () => {
    it('asks for the code after the password, with a one-time-code numeric input focused', async () => {
        mockApi(() => new Promise(() => {}));
        await passPassword();

        expect(screen.getByText('anna@example.org')).toBeInTheDocument();
        const code = screen.getByLabelText('Two-factor code');
        expect(code).toHaveFocus();
        expect(code).toHaveAttribute('inputmode', 'numeric');
        expect(code).toHaveAttribute('autocomplete', 'one-time-code');
        expect(screen.queryByLabelText('Password')).not.toBeInTheDocument();
    });

    it('shows the server error for a wrong code, linked to the field', async () => {
        mockApi(() => Promise.reject(invalid('code', 'The code is wrong or has expired.')));
        const { user } = await passPassword();

        await user.type(screen.getByLabelText('Two-factor code'), '000000');
        await user.click(screen.getByRole('button', { name: 'Sign in' }));

        const alert = await screen.findByRole('alert');
        expect(alert).toHaveTextContent('The code is wrong or has expired.');
        const code = screen.getByLabelText('Two-factor code');
        expect(code).toHaveAttribute('aria-invalid', 'true');
        expect(code).toHaveAttribute('aria-describedby', alert.id);
        expect(screen.getByRole('heading', { name: 'Enter your code' })).toBeInTheDocument();
    });

    it('strips spaces from a pasted code and posts the 6 digits', async () => {
        const challenge = vi.fn<Challenge>(() => new Promise(() => {}));
        mockApi(challenge);
        const { user } = await passPassword();

        await user.type(screen.getByLabelText('Two-factor code'), '123 456');
        await user.click(screen.getByRole('button', { name: 'Sign in' }));

        expect(challenge).toHaveBeenCalledWith({ code: '123456' });
    });

    it('posts recovery_code in recovery-code mode', async () => {
        const challenge = vi.fn<Challenge>(() => Promise.reject(invalid('recovery_code', 'That recovery code is wrong or has already been used.')));
        mockApi(challenge);
        const { user } = await passPassword();

        await user.click(screen.getByRole('button', { name: 'Use a recovery code' }));
        expect(screen.getByRole('heading', { name: 'Use a recovery code' })).toBeInTheDocument();
        const field = screen.getByLabelText('Recovery code');
        expect(field).toHaveFocus();
        expect(field).not.toHaveAttribute('inputmode', 'numeric');
        await user.type(field, ' AbCdEfGhIj-KlMnOpQrSt ');
        await user.click(screen.getByRole('button', { name: 'Sign in' }));

        expect(challenge).toHaveBeenCalledWith({ recovery_code: 'AbCdEfGhIj-KlMnOpQrSt' });
        expect(await screen.findByRole('alert')).toHaveTextContent('That recovery code is wrong or has already been used.');

        await user.click(screen.getByRole('button', { name: 'Use your authenticator app instead' }));
        expect(screen.getByLabelText('Two-factor code')).toHaveFocus();
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });

    it('on success stores me and goes to next', async () => {
        mockApi(() => Promise.resolve(ME));
        const { user, client } = await passPassword('/signin?next=%2Fdomains%2Fexample.org');

        await user.type(screen.getByLabelText('Two-factor code'), '123456');
        await user.click(screen.getByRole('button', { name: 'Sign in' }));

        expect(await screen.findByTestId('location')).toHaveTextContent('/domains/example.org');
        expect(client.getQueryData(queryKeys.me)).toEqual(ME);
    });

    it('shows an expired pending sign-in and goes back to the password form', async () => {
        mockApi(() => Promise.reject(invalid('code', 'Your sign-in has expired. Enter your email and password again.')));
        const { user } = await passPassword();

        await user.type(screen.getByLabelText('Two-factor code'), '123456');
        await user.click(screen.getByRole('button', { name: 'Sign in' }));
        expect(await screen.findByRole('alert')).toHaveTextContent('Your sign-in has expired.');

        await user.click(screen.getByRole('button', { name: 'Back to sign in' }));
        expect(screen.getByRole('heading', { name: 'Sign in' })).toBeInTheDocument();
        expect(screen.getByLabelText('Email')).toHaveValue('anna@example.org');
        expect(screen.getByLabelText('Password')).toHaveValue('');
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });

    it('drops the error when the language changes', async () => {
        mockApi(() => Promise.reject(invalid('code', 'The code is wrong or has expired.')));
        const { user } = await passPassword();
        await user.type(screen.getByLabelText('Two-factor code'), '000000');
        await user.click(screen.getByRole('button', { name: 'Sign in' }));
        await screen.findByRole('alert');

        await act(() => setLocale('fi'));

        expect(screen.getByRole('heading', { name: 'Syötä koodi' })).toBeInTheDocument();
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });

    it('renders in Finnish', async () => {
        await setLocale('fi');
        mockApi(() => new Promise(() => {}));
        renderRoutes(routes, '/signin');
        const user = userEvent.setup();
        await user.type(await screen.findByLabelText('Sähköposti'), 'anna@example.org');
        await user.type(screen.getByLabelText('Salasana'), 'correct horse battery');
        await user.click(screen.getByRole('button', { name: 'Jatka' }));

        expect(await screen.findByRole('heading', { name: 'Syötä koodi' })).toBeInTheDocument();
        expect(screen.getByLabelText('Kaksivaiheisen tunnistautumisen koodi')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Kirjaudu sisään' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Käytä varakoodia' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Takaisin sisäänkirjautumiseen' })).toBeInTheDocument();
    });
});
