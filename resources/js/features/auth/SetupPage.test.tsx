import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { apiFetch } from '../../api/client';
import { ApiProblem } from '../../api/problems';
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

    it('posts the token, email and both passwords, then goes to sign-in with the banner flag', async () => {
        vi.mocked(apiFetch).mockResolvedValue(ME);
        const user = userEvent.setup();
        renderRoutes(routes, URL);

        await user.type(screen.getByLabelText('New password'), STRONG);
        await user.type(screen.getByLabelText('Confirm password'), STRONG);
        await user.click(screen.getByRole('button', { name: 'Set password' }));

        expect(await screen.findByTestId('location')).toHaveTextContent('/signin?setup=1');
        expect(apiFetch).toHaveBeenCalledWith('/auth/setup', {
            method: 'POST',
            json: { token: 'tok123', email: 'anna@example.org', password: STRONG, password_confirmation: STRONG },
        });
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
});
