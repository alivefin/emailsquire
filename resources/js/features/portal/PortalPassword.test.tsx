import { beforeEach, describe, expect, it, vi } from 'vitest';
import { act, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { apiFetch } from '../../api/client';
import { ApiProblem } from '../../api/problems';
import { setLocale } from '../../i18n';
import { portalApi } from '../../test/portalServer';
import { renderRoutes } from '../../test/routing';
import { PortalPassword } from './PortalPassword';

vi.mock('../../api/client', () => ({
    apiFetch: vi.fn(),
    onSessionExpired: vi.fn(() => () => {}),
    onTwoFactorRequired: vi.fn(() => () => {}),
    onStepUpRequired: vi.fn(() => () => {}),
    ensureCsrf: vi.fn(),
}));

const routes = [{ path: '/my/password', element: <PortalPassword /> }];
const STRONG = 'Correct-Horse-42';

function renderPassword(put: () => unknown = () => undefined) {
    vi.mocked(apiFetch).mockImplementation(portalApi('mailbox', { '/portal/password': put }));
    return renderRoutes(routes, '/my/password');
}

async function fill(current = 'summersun', password = STRONG, confirmation = password) {
    const user = userEvent.setup();
    await user.type(await screen.findByLabelText('Current password'), current);
    await user.type(screen.getByLabelText('New password'), password);
    await user.type(screen.getByLabelText('Confirm new password'), confirmation);
    return user;
}

const submitButton = () => screen.getByRole('button', { name: 'Change password' });

function validation(errors: Record<string, string[]>) {
    return new ApiProblem({ status: 422, title: 'Unprocessable Content', code: 'validation_failed', errors });
}

beforeEach(() => {
    vi.mocked(apiFetch).mockReset();
});

describe('PortalPassword', () => {
    it('states the rules before anything is typed, with show/hide and the strength meter', async () => {
        renderPassword();

        expect(await screen.findByRole('heading', { level: 1, name: 'Change password' })).toBeInTheDocument();
        const rules = screen.getByRole('list', { name: 'Your new password needs' });
        expect(rules).toHaveTextContent('At least 12 characters.');
        expect(rules).toHaveTextContent('A number and a symbol. With 16 characters or more, one of them is enough.');
        expect(rules).toHaveTextContent('At most 72 characters. Letters such as ä and ö count as two.');
        expect(screen.getByRole('meter', { name: 'Password strength' })).toHaveAttribute('aria-valuenow', '0');
        expect(screen.getByRole('button', { name: 'Show' })).toBeInTheDocument();
        // The user must remember it: no Generate button.
        expect(screen.queryByRole('button', { name: 'Generate' })).not.toBeInTheDocument();
        expect(submitButton()).toBeDisabled();
    });

    it('changes the password, says so clearly and empties the form', async () => {
        const put = vi.fn(() => undefined);
        renderPassword(put);
        const user = await fill();
        expect(screen.getByRole('meter', { name: 'Password strength' })).toHaveAttribute('aria-valuenow', '4');
        await user.click(submitButton());

        const success = await screen.findByText('Password changed. Use the new password in your email apps from now on.');
        expect(success.closest('[role="status"]')).not.toBeNull();
        expect(apiFetch).toHaveBeenCalledWith('/portal/password', {
            method: 'PUT',
            json: { current_password: 'summersun', password: STRONG, password_confirmation: STRONG },
        });
        expect(screen.getByLabelText('Current password')).toHaveValue('');
        expect(screen.getByLabelText('New password')).toHaveValue('');
        expect(screen.getByLabelText('Confirm new password')).toHaveValue('');
    });

    it('shows a wrong current password under its field', async () => {
        renderPassword(() => {
            throw validation({ current_password: ['The current password is wrong.'] });
        });
        const user = await fill('wrong-password');
        await user.click(submitButton());

        expect(await screen.findByText('The current password is wrong.')).toBeInTheDocument();
        expect(screen.getByLabelText('Current password')).toHaveAttribute('aria-invalid', 'true');
        expect(screen.queryByText(/Password changed/)).not.toBeInTheDocument();
    });

    it('shows the server refusing the new password under the new password', async () => {
        renderPassword(() => {
            throw validation({ password: ['This password has appeared in a data breach. Choose another.'] });
        });
        const user = await fill();
        await user.click(submitButton());

        expect(await screen.findByRole('alert')).toHaveTextContent('This password has appeared in a data breach. Choose another.');
        expect(screen.getByLabelText('New password')).toHaveAttribute('aria-invalid', 'true');
    });

    it('shows too many attempts inline', async () => {
        renderPassword(() => {
            throw new ApiProblem({ status: 429, title: 'Too Many Requests', code: 'too_many_requests' });
        });
        const user = await fill();
        await user.click(submitButton());

        expect(await screen.findByRole('alert')).toHaveTextContent('Too many attempts. Wait a minute and try again.');
    });

    it('says the current password cannot be checked right now when the mail server is down', async () => {
        renderPassword(() => {
            throw new ApiProblem({ status: 503, title: 'Service Unavailable', code: 'password_check_unavailable' });
        });
        const user = await fill();
        await user.click(submitButton());

        expect(await screen.findByRole('alert')).toHaveTextContent(
            "Your current password can't be checked right now. Try again in a moment.",
        );
    });

    it('refuses a password over 72 bytes before sending it, and a mismatched confirmation', async () => {
        renderPassword();
        const user = await fill('summersun', `${'ä'.repeat(36)}1!`, 'a different and much longer confirmation text');

        expect(screen.getByText('Too long: at most 72 characters. Letters such as ä and ö count as two.')).toBeInTheDocument();
        expect(screen.getByText("The passwords don't match.")).toBeInTheDocument();
        expect(submitButton()).toBeDisabled();
        await user.click(submitButton());
        expect(vi.mocked(apiFetch).mock.calls.filter(([p]) => p === '/portal/password')).toEqual([]);
    });

    it('drops server errors when the language changes', async () => {
        renderPassword(() => {
            throw validation({ current_password: ['The current password is wrong.'] });
        });
        const user = await fill('wrong-password');
        await user.click(submitButton());
        await screen.findByText('The current password is wrong.');

        await act(() => setLocale('fi'));

        await waitFor(() => expect(screen.queryByText('The current password is wrong.')).not.toBeInTheDocument());
    });

    it('renders in Finnish', async () => {
        await act(() => setLocale('fi'));
        renderPassword();

        expect(await screen.findByRole('heading', { level: 1, name: 'Vaihda salasana' })).toBeInTheDocument();
        expect(screen.getByRole('list', { name: 'Uudessa salasanassasi pitää olla' })).toHaveTextContent('Vähintään 12 merkkiä.');
        expect(screen.getByLabelText('Nykyinen salasana')).toBeInTheDocument();
        expect(screen.getByLabelText('Uusi salasana')).toBeInTheDocument();
        expect(screen.getByLabelText('Vahvista uusi salasana')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Vaihda salasana' })).toBeDisabled();
    });
});
