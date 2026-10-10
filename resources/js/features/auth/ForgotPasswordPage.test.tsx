import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { apiFetch } from '../../api/client';
import { ApiProblem } from '../../api/problems';
import { setLocale } from '../../i18n';
import { renderRoutes } from '../../test/routing';
import { ForgotPasswordPage } from './ForgotPasswordPage';

vi.mock('../../api/client', () => ({
    apiFetch: vi.fn(),
    onSessionExpired: vi.fn(() => () => {}),
    onTwoFactorRequired: vi.fn(() => () => {}), onStepUpRequired: vi.fn(() => () => {}),
    ensureCsrf: vi.fn(),
}));

const routes = [{ path: '/forgot-password', element: <ForgotPasswordPage /> }];
const SENT = "If an account exists for that address, we've sent a link. It works for 30 minutes.";

async function request(email: string) {
    renderRoutes(routes, '/forgot-password');
    const user = userEvent.setup();
    await user.type(screen.getByLabelText('Email'), email);
    await user.click(screen.getByRole('button', { name: 'Send link' }));
    return user;
}

beforeEach(() => {
    vi.mocked(apiFetch).mockReset();
});

describe('ForgotPasswordPage', () => {
    it('shows the form without asking the server who is signed in', () => {
        renderRoutes(routes, '/forgot-password');
        expect(screen.getByRole('heading', { name: 'Reset your password' })).toBeInTheDocument();
        expect(screen.getByLabelText('Email')).toHaveAttribute('autocomplete', 'username');
        expect(screen.getByRole('link', { name: 'Back to sign in' })).toHaveAttribute('href', '/signin');
        expect(apiFetch).not.toHaveBeenCalled();
    });

    it.each(['anna@example.org', 'nobody@example.org'])('shows the same confirmation for %s', async (email) => {
        vi.mocked(apiFetch).mockResolvedValue({ accepted: true });
        await request(` ${email} `);

        expect(apiFetch).toHaveBeenCalledWith('/auth/forgot-password', { method: 'POST', json: { email } });
        expect(await screen.findByText(SENT)).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Check your email' })).toHaveFocus();
        expect(screen.queryByLabelText('Email')).not.toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Back to sign in' })).toHaveAttribute('href', '/signin');
    });

    it('shows the too-many-requests message instead of the confirmation when throttled', async () => {
        vi.mocked(apiFetch).mockRejectedValue(
            new ApiProblem({ status: 429, title: 'Too Many Requests', code: 'too_many_requests' }),
        );
        await request('anna@example.org');

        expect(await screen.findByRole('alert')).toHaveTextContent('Too many attempts. Wait a minute and try again.');
        expect(screen.queryByText(SENT)).not.toBeInTheDocument();
        expect(screen.getByLabelText('Email')).toHaveValue('anna@example.org');
    });

    it('shows a field error for a malformed address', async () => {
        vi.mocked(apiFetch).mockRejectedValue(
            new ApiProblem({ status: 422, title: 'Invalid', errors: { email: ['The email field must be a valid email address.'] } }),
        );
        await request('anna');

        const alert = await screen.findByRole('alert');
        expect(alert).toHaveTextContent('The email field must be a valid email address.');
        expect(screen.getByLabelText('Email')).toHaveAttribute('aria-describedby', alert.id);
    });

    it('does not send an empty address', async () => {
        renderRoutes(routes, '/forgot-password');
        await userEvent.click(screen.getByRole('button', { name: 'Send link' }));
        expect(apiFetch).not.toHaveBeenCalled();
    });

    it('renders in Finnish', async () => {
        await setLocale('fi');
        vi.mocked(apiFetch).mockResolvedValue({ accepted: true });
        renderRoutes(routes, '/forgot-password');
        expect(screen.getByRole('heading', { name: 'Nollaa salasanasi' })).toBeInTheDocument();
        const user = userEvent.setup();
        await user.type(screen.getByLabelText('Sähköposti'), 'anna@example.org');
        await user.click(screen.getByRole('button', { name: 'Lähetä linkki' }));
        expect(
            await screen.findByText('Jos tällä osoitteella on tili, lähetimme siihen palautuslinkin. Linkki on voimassa 30 minuuttia.'),
        ).toBeInTheDocument();
    });
});
