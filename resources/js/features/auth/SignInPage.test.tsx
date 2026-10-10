import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { apiFetch } from '../../api/client';
import { ApiProblem } from '../../api/problems';
import { queryKeys } from '../../api/types';
import { renderRoutes } from '../../test/routing';
import { SignInPage, safeNext } from './SignInPage';

vi.mock('../../api/client', () => ({
    apiFetch: vi.fn(),
    onSessionExpired: vi.fn(() => () => {}),
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
    it('shows the card with the server-admin subtitle and no forgot-password link', async () => {
        mockApi(() => Promise.resolve(ME));
        renderRoutes(routes, '/signin');

        expect(await screen.findByRole('heading', { name: 'Sign in' })).toBeInTheDocument();
        expect(screen.getByText('For server admins.')).toBeInTheDocument();
        expect(screen.queryByText(/forgot password/i)).not.toBeInTheDocument();
        expect(screen.queryByText(/session expired/i)).not.toBeInTheDocument();
    });

    it('shows the exact copy for a wrong password', async () => {
        mockApi(() => Promise.reject(wrongPassword));
        renderRoutes(routes, '/signin');

        const user = await signIn('wrong');
        await user.click(screen.getByRole('button', { name: 'Continue' }));

        expect(await screen.findByRole('alert')).toHaveTextContent('Email or password is wrong.');
        expect(screen.getByRole('heading', { name: 'Sign in' })).toBeInTheDocument();
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
            await screen.findByText('Your session expired after 30 minutes. Sign in again to continue.'),
        ).toBeInTheDocument();
    });

    it('shows the password-set banner for setup=1', async () => {
        mockApi(() => Promise.resolve(ME));
        renderRoutes(routes, '/signin?setup=1');

        expect(await screen.findByText('Password set. Sign in with your new password.')).toBeInTheDocument();
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
