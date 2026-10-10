import { beforeEach, describe, expect, it, vi } from 'vitest';
import { act, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { apiFetch } from '../../api/client';
import { ApiProblem } from '../../api/problems';
import { queryKeys } from '../../api/types';
import { setLocale } from '../../i18n';
import { renderRoutes } from '../../test/routing';
import { SignInPage, safeNext } from './SignInPage';

vi.mock('../../api/client', () => ({
    apiFetch: vi.fn(),
    onSessionExpired: vi.fn(() => () => {}),
    onTwoFactorRequired: vi.fn(() => () => {}), onStepUpRequired: vi.fn(() => () => {}),
    ensureCsrf: vi.fn(),
}));

const ME = { id: 1, name: 'Anna Admin', email: 'anna@example.org', is_server_admin: true };
const unauthenticated = new ApiProblem({ status: 401, title: 'Unauthorized', code: 'unauthenticated' });
const wrongPassword = new ApiProblem({
    status: 422,
    title: 'Unprocessable Content',
    code: 'validation_failed',
    errors: { email: ['Email or password is wrong.'] },
});

const routes = [{ path: '/signin', element: <SignInPage /> }];

function mockApi(login: () => Promise<unknown>) {
    vi.mocked(apiFetch).mockImplementation((path: string) => {
        if (path === '/me') return Promise.reject(unauthenticated);
        if (path === '/auth/login') return login();
        return Promise.reject(new Error(`unexpected ${path}`));
    });
}

async function signIn(password = 'correct horse battery') {
    const user = userEvent.setup();
    await user.type(await screen.findByLabelText('Email'), 'anna@example.org');
    await user.type(screen.getByLabelText('Password'), password);
    return user;
}

beforeEach(() => {
    vi.mocked(apiFetch).mockReset();
});

describe('SignInPage', () => {
    it('shows the card with a subtitle for every role and the forgot-password link', async () => {
        mockApi(() => Promise.resolve(ME));
        renderRoutes(routes, '/signin');

        expect(await screen.findByRole('heading', { name: 'Sign in' })).toBeInTheDocument();
        expect(screen.getByText('Sign in to manage your mail domains.')).toBeInTheDocument();
        expect(screen.queryByText(/server admins/i)).not.toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Forgot password?' })).toHaveAttribute('href', '/forgot-password');
        expect(screen.queryByText(/password changed/i)).not.toBeInTheDocument();
        expect(screen.queryByText(/session expired/i)).not.toBeInTheDocument();
        expect(screen.getByLabelText('Password')).toHaveAttribute('type', 'password');
    });

    it('shows the exact copy for a wrong password', async () => {
        mockApi(() => Promise.reject(wrongPassword));
        renderRoutes(routes, '/signin');

        const user = await signIn('wrong');
        await user.click(screen.getByRole('button', { name: 'Continue' }));

        expect(await screen.findByRole('alert')).toHaveTextContent('Email or password is wrong.');
        expect(screen.getByRole('heading', { name: 'Sign in' })).toBeInTheDocument();
    });

    it('drops the error when the language changes, since it was in the old language', async () => {
        mockApi(() => Promise.reject(wrongPassword));
        renderRoutes(routes, '/signin');
        const user = await signIn('wrong');
        await user.click(screen.getByRole('button', { name: 'Continue' }));
        expect(await screen.findByRole('alert')).toHaveTextContent('Email or password is wrong.');

        await act(() => setLocale('fi'));

        expect(screen.getByRole('heading', { name: 'Kirjaudu sisään' })).toBeInTheDocument();
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
        expect(screen.getByLabelText('Salasana')).not.toHaveAttribute('aria-invalid');
    });

    it('submits on Enter, sets the me query and returns to next with its query string', async () => {
        mockApi(() => Promise.resolve(ME));
        const { client } = renderRoutes(routes, '/signin?next=%2Fdomains%2Fexample.org%2Fmailboxes%3Fq%3Djo');

        const user = await signIn();
        await user.keyboard('{Enter}');

        expect(await screen.findByTestId('location')).toHaveTextContent('/domains/example.org/mailboxes?q=jo');
        expect(apiFetch).toHaveBeenCalledWith('/auth/login', {
            method: 'POST',
            json: { email: 'anna@example.org', password: 'correct horse battery' },
        });
        expect(client.getQueryData(queryKeys.me)).toEqual(ME);
    });

    it('falls back to / when next points off-site', async () => {
        mockApi(() => Promise.resolve(ME));
        renderRoutes(routes, '/signin?next=%2F%2Fevil.example');

        const user = await signIn();
        await user.click(screen.getByRole('button', { name: 'Continue' }));

        expect(await screen.findByTestId('location')).toHaveTextContent(/^\/$/);
    });

    it('shows the session expired banner for expired=1', async () => {
        mockApi(() => Promise.resolve(ME));
        renderRoutes(routes, '/signin?next=%2Fdomains&expired=1');

        expect(
            await screen.findByText('Your session has expired. Sign in again to continue.'),
        ).toBeInTheDocument();
    });

    it('lands a user with one domain on its mailboxes when there is no next', async () => {
        mockApi(() => Promise.resolve({ ...ME, is_server_admin: false, landing: 'xn--bcher-kva.example' }));
        renderRoutes(routes, '/signin');

        const user = await signIn();
        await user.click(screen.getByRole('button', { name: 'Continue' }));

        expect(await screen.findByTestId('location')).toHaveTextContent(/^\/domains\/xn--bcher-kva\.example\/mailboxes$/);
    });

    it('lands on the single domain when next is just / (the guard sends a bare visit there)', async () => {
        mockApi(() => Promise.resolve({ ...ME, is_server_admin: false, landing: 'example.org' }));
        renderRoutes(routes, '/signin?next=%2F');

        const user = await signIn();
        await user.click(screen.getByRole('button', { name: 'Continue' }));

        expect(await screen.findByTestId('location')).toHaveTextContent(/^\/domains\/example\.org\/mailboxes$/);
    });

    it('prefers next over the landing domain', async () => {
        mockApi(() => Promise.resolve({ ...ME, is_server_admin: false, landing: 'example.org' }));
        renderRoutes(routes, '/signin?next=%2Fprofile');

        const user = await signIn();
        await user.click(screen.getByRole('button', { name: 'Continue' }));

        expect(await screen.findByTestId('location')).toHaveTextContent(/^\/profile$/);
    });

    it('sends a signed-in visitor to /', async () => {
        vi.mocked(apiFetch).mockResolvedValue(ME);
        renderRoutes(routes, '/signin');

        expect(await screen.findByTestId('location')).toHaveTextContent(/^\/$/);
    });
});

describe('safeNext', () => {
    it.each([
        [null, '/'],
        ['', '/'],
        ['domains', '/'],
        ['https://evil.example/', '/'],
        ['//evil.example', '/'],
        ['/\\evil.example', '/'],
        ['/\t/evil.example', '/'],
        ['/signin', '/'],
        ['/signin?next=%2F', '/'],
        ['/', '/'],
        ['/domains/example.org/mailboxes?q=jo', '/domains/example.org/mailboxes?q=jo'],
        ['/addresses?type=forward&page=2', '/addresses?type=forward&page=2'],
    ])('maps %j to %j', (raw, expected) => {
        expect(safeNext(raw)).toBe(expected);
    });
});
