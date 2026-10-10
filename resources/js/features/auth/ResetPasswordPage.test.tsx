import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { apiFetch } from '../../api/client';
import { ApiProblem } from '../../api/problems';
import { queryKeys } from '../../api/types';
import { setLocale } from '../../i18n';
import { renderRoutes, testQueryClient } from '../../test/routing';
import { ResetPasswordPage } from './ResetPasswordPage';
import { SignInPage } from './SignInPage';

vi.mock('../../api/client', () => ({
    apiFetch: vi.fn(),
    onSessionExpired: vi.fn(() => () => {}),
    onTwoFactorRequired: vi.fn(() => () => {}), onStepUpRequired: vi.fn(() => () => {}),
    ensureCsrf: vi.fn(),
}));

const routes = [
    { path: '/reset-password/:token', element: <ResetPasswordPage /> },
    { path: '/signin', element: <SignInPage /> },
];
const PATH = '/reset-password/tok123?email=anna%40example.org';
const STRONG = 'Correct-Horse-Battery-9';

function mockApi(reset: () => Promise<unknown>) {
    vi.mocked(apiFetch).mockImplementation((path: string) => {
        if (path === '/auth/reset-password') return reset();
        if (path === '/me') return Promise.reject(new ApiProblem({ status: 401, title: 'Unauthorized' }));
        return Promise.reject(new Error(`unexpected ${path}`));
    });
}

async function fill(password = STRONG, confirmation = password) {
    const user = userEvent.setup();
    await user.type(screen.getByLabelText('New password'), password);
    await user.type(screen.getByLabelText('Confirm password'), confirmation);
    return user;
}

beforeEach(() => {
    vi.mocked(apiFetch).mockReset();
});

describe('ResetPasswordPage', () => {
    it('shows the email from the link, the strength meter and no Generate button', () => {
        renderRoutes(routes, PATH);
        expect(screen.getByRole('heading', { name: 'Choose a new password' })).toBeInTheDocument();
        expect(screen.getByLabelText('Email')).toHaveValue('anna@example.org');
        expect(screen.getByLabelText('Email')).toHaveAttribute('readonly');
        expect(screen.getByText('At least 12 characters, not just letters.')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Generate' })).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Set new password' })).toBeDisabled();
        expect(apiFetch).not.toHaveBeenCalled();
    });

    it('posts the token, email and passwords, then signs in fresh with the banner', async () => {
        mockApi(() => Promise.resolve({ reset: true }));
        const client = testQueryClient();
        client.setQueryData(queryKeys.me, { id: 9, email: 'someone@example.org' });
        client.setQueryData(['domains', {}], { data: [] });
        renderRoutes(routes, PATH, client);
        const user = await fill();
        await user.click(screen.getByRole('button', { name: 'Set new password' }));

        expect(apiFetch).toHaveBeenCalledWith('/auth/reset-password', {
            method: 'POST',
            json: { token: 'tok123', email: 'anna@example.org', password: STRONG, password_confirmation: STRONG },
        });
        expect(await screen.findByRole('heading', { name: 'Sign in' })).toBeInTheDocument();
        expect(screen.getByText('Password changed. Sign in with your new password.').closest('[role="status"]')).not.toBeNull();
        expect(client.getQueryData(['domains', {}])).toBeUndefined();
    });

    it('shows an invalid or expired link with a way to request a new one', async () => {
        mockApi(() =>
            Promise.reject(
                new ApiProblem({ status: 422, title: 'Invalid', errors: { token: ['This reset link is invalid or has expired.'] } }),
            ),
        );
        renderRoutes(routes, PATH);
        const user = await fill();
        await user.click(screen.getByRole('button', { name: 'Set new password' }));

        const alert = await screen.findByRole('alert');
        expect(alert).toHaveTextContent('This reset link is invalid or has expired.');
        expect(screen.getByRole('link', { name: 'Request a new link' })).toHaveAttribute('href', '/forgot-password');
        expect(screen.getByRole('heading', { name: 'Choose a new password' })).toBeInTheDocument();
    });

    it('shows a weak-password error under the password field', async () => {
        mockApi(() =>
            Promise.reject(
                new ApiProblem({ status: 422, title: 'Invalid', errors: { password: ['This password has appeared in a data leak.'] } }),
            ),
        );
        renderRoutes(routes, PATH);
        const user = await fill();
        await user.click(screen.getByRole('button', { name: 'Set new password' }));

        const alert = await screen.findByRole('alert');
        expect(alert).toHaveTextContent('This password has appeared in a data leak.');
        expect(screen.getByLabelText('New password')).toHaveAttribute('aria-describedby', alert.id);
    });

    it('flags a link without the email and offers a new one', () => {
        renderRoutes(routes, '/reset-password/tok123');
        expect(screen.getByRole('alert')).toHaveTextContent('This reset link is missing the email address.');
        expect(screen.getByRole('link', { name: 'Request a new link' })).toHaveAttribute('href', '/forgot-password');
    });

    it('renders in Finnish', async () => {
        await setLocale('fi');
        renderRoutes(routes, PATH);
        expect(screen.getByRole('heading', { name: 'Valitse uusi salasana' })).toBeInTheDocument();
        expect(screen.getByLabelText('Uusi salasana')).toBeInTheDocument();
        expect(screen.getByLabelText('Vahvista salasana')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Aseta uusi salasana' })).toBeInTheDocument();
    });
});
