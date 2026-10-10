import { beforeEach, describe, expect, it, vi } from 'vitest';
import { act, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { apiFetch } from '../../api/client';
import { ApiProblem } from '../../api/problems';
import { queryKeys } from '../../api/types';
import { setLocale } from '../../i18n';
import { renderRoutes } from '../../test/routing';
import { SetupPage } from './SetupPage';

vi.mock('../../api/client', () => ({
    apiFetch: vi.fn(),
    onSessionExpired: vi.fn(() => () => {}),
    ensureCsrf: vi.fn(),
}));

const ME = { id: 1, name: 'Anna Admin', email: 'anna@example.org', is_server_admin: true };
const STRONG = 'tidy-Harbor-42-lamp';
const routes = [{ path: '/setup/:token', element: <SetupPage /> }];
const URL = '/setup/tok123?email=anna%40example.org';

beforeEach(() => {
    vi.mocked(apiFetch).mockReset();
});

describe('SetupPage', () => {
    it('shows the email from the link as read-only', () => {
        renderRoutes(routes, URL);

        expect(screen.getByRole('heading', { name: 'Set your password' })).toBeInTheDocument();
        const email = screen.getByLabelText('Email');
        expect(email).toHaveValue('anna@example.org');
        expect(email).toHaveAttribute('readonly');
    });

    it('offers no Generate button: the admin types a password they will remember', () => {
        renderRoutes(routes, URL);

        expect(screen.queryByRole('button', { name: 'Generate' })).not.toBeInTheDocument();
    });

    it('keeps real password inputs, so the browser may save the console password', () => {
        renderRoutes(routes, URL);

        expect(screen.getByLabelText('New password')).toHaveAttribute('type', 'password');
        expect(screen.getByLabelText('New password')).toHaveAttribute('autocomplete', 'new-password');
        expect(screen.getByLabelText('Confirm password')).toHaveAttribute('type', 'password');
    });

    it('keeps submit disabled until strength, length and confirmation all hold', async () => {
        const user = userEvent.setup();
        renderRoutes(routes, URL);
        const submit = screen.getByRole('button', { name: 'Set password' });
        const password = screen.getByLabelText('New password');
        const confirm = screen.getByLabelText('Confirm password');

        expect(submit).toBeDisabled();

        // Score 2: twelve letters and digits, no symbol.
        await user.type(password, 'abcdefghij12');
        await user.type(confirm, 'abcdefghij12');
        expect(submit).toBeDisabled();

        await user.clear(password);
        await user.clear(confirm);
        await user.type(password, STRONG);
        await user.type(confirm, `${STRONG}x`);
        expect(submit).toBeDisabled();

        await user.type(confirm, '{Backspace}');
        expect(submit).toBeEnabled();
    });

    it('posts the token, email and both passwords, stores the signed-in admin and goes to / with a toast', async () => {
        vi.mocked(apiFetch).mockResolvedValue(ME);
        const user = userEvent.setup();
        const { client } = renderRoutes(routes, URL);

        await user.type(screen.getByLabelText('New password'), STRONG);
        await user.type(screen.getByLabelText('Confirm password'), STRONG);
        await user.click(screen.getByRole('button', { name: 'Set password' }));

        expect(await screen.findByTestId('location')).toHaveTextContent(/^\/$/);
        expect(screen.getByText("Password set. You're signed in.")).toBeInTheDocument();
        expect(client.getQueryData(queryKeys.me)).toEqual(ME);
        expect(apiFetch).toHaveBeenCalledTimes(1);
        expect(apiFetch).toHaveBeenCalledWith('/auth/setup', {
            method: 'POST',
            json: { token: 'tok123', email: 'anna@example.org', password: STRONG, password_confirmation: STRONG },
        });
    });

    it('refetches me when the setup response is not the me payload', async () => {
        vi.mocked(apiFetch).mockImplementation((path: string) =>
            Promise.resolve(path === '/me' ? ME : undefined),
        );
        const user = userEvent.setup();
        const { client } = renderRoutes(routes, URL);
        client.setQueryData(queryKeys.me, null);

        await user.type(screen.getByLabelText('New password'), STRONG);
        await user.type(screen.getByLabelText('Confirm password'), `${STRONG}{Enter}`);

        expect(await screen.findByTestId('location')).toHaveTextContent(/^\/$/);
        await waitFor(() => expect(client.getQueryData(queryKeys.me)).toEqual(ME));
        expect(apiFetch).toHaveBeenCalledWith('/me');
    });

    it('shows the invalid-link error and the field errors from a 422', async () => {
        vi.mocked(apiFetch).mockRejectedValue(
            new ApiProblem({
                status: 422,
                title: 'Unprocessable Content',
                code: 'validation_failed',
                errors: {
                    token: ['This setup link is invalid or has expired.'],
                    password: ['The given password has appeared in a data leak.'],
                },
            }),
        );
        const user = userEvent.setup();
        renderRoutes(routes, URL);

        await user.type(screen.getByLabelText('New password'), STRONG);
        await user.type(screen.getByLabelText('Confirm password'), `${STRONG}{Enter}`);

        expect(await screen.findByText('This setup link is invalid or has expired.')).toBeInTheDocument();
        expect(screen.getByText('The given password has appeared in a data leak.')).toBeInTheDocument();
        expect(screen.queryByTestId('location')).not.toBeInTheDocument();
    });

    it('drops the server errors when the language changes, since they were in the old language', async () => {
        vi.mocked(apiFetch).mockRejectedValue(
            new ApiProblem({
                status: 422,
                title: 'Unprocessable Content',
                code: 'validation_failed',
                errors: {
                    token: ['This setup link is invalid or has expired.'],
                    password: ['The given password has appeared in a data leak.'],
                },
            }),
        );
        const user = userEvent.setup();
        renderRoutes(routes, URL);
        await user.type(screen.getByLabelText('New password'), STRONG);
        await user.type(screen.getByLabelText('Confirm password'), `${STRONG}{Enter}`);
        expect(await screen.findByText('This setup link is invalid or has expired.')).toBeInTheDocument();

        await act(() => setLocale('fi'));

        expect(screen.getByRole('heading', { name: 'Aseta salasanasi' })).toBeInTheDocument();
        expect(screen.queryByText('This setup link is invalid or has expired.')).not.toBeInTheDocument();
        expect(screen.queryByText('The given password has appeared in a data leak.')).not.toBeInTheDocument();
    });
});
