import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { apiFetch } from '../api/client';
import { ApiProblem } from '../api/problems';
import { routes } from '../router';
import { renderRoutes } from '../test/routing';

vi.mock('../api/client', () => ({ apiFetch: vi.fn(), onSessionExpired: vi.fn(() => () => {}), onTwoFactorRequired: vi.fn(() => () => {}), onStepUpRequired: vi.fn(() => () => {}), ensureCsrf: vi.fn() }));

const ME = { id: 1, name: 'Anna Admin', email: 'anna@example.org', is_server_admin: true };

let signedOut = false;

beforeEach(() => {
    signedOut = false;
    vi.mocked(apiFetch).mockReset();
    vi.mocked(apiFetch).mockImplementation((path: string) => {
        if (path === '/me')
            return signedOut ? Promise.reject(new ApiProblem({ status: 401, title: 'Unauthorized' })) : Promise.resolve(ME);
        if (path === '/auth/logout') {
            signedOut = true;
            return Promise.reject(new Error('offline'));
        }
        if (path === '/dashboard')
            return Promise.resolve({
                counts: {
                    domains: 0,
                    domains_added_this_month: 0,
                    mailboxes: 0,
                    forwarding: 0,
                    forwarding_local: 0,
                    forwarding_external: 0,
                },
                near_full: { count: 0, items: [] },
                recent_activity: [],
                server_storage: null,
                usage_checked_at: null,
            });
        return Promise.resolve({ data: [] });
    });
});

describe('AppLayout', () => {
    it('redirects a domain without a tab to its mailboxes', async () => {
        const { router } = renderRoutes(routes, '/domains/example.org');
        await waitFor(() => expect(router.state.location.pathname).toBe('/domains/example.org/mailboxes'));
    });

    it('renders Page not found inside the layout', async () => {
        renderRoutes(routes, '/nope');
        expect(await screen.findByText('Page not found', { selector: 'h2' })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Back to the dashboard' })).toBeInTheDocument();
    });

    it('signs out even when the request fails: clears the cache and goes to /signin', async () => {
        renderRoutes(routes, '/');
        const user = userEvent.setup();
        await user.click(await screen.findByRole('button', { name: 'Account menu' }));
        expect(screen.getByText('anna@example.org')).toBeInTheDocument();
        expect(screen.getByText('Server admin')).toBeInTheDocument();
        await user.click(screen.getByRole('menuitem', { name: 'Sign out' }));

        await waitFor(() => expect(apiFetch).toHaveBeenCalledWith('/auth/logout', { method: 'POST' }));
        expect(await screen.findByRole('heading', { name: 'Sign in' })).toBeInTheDocument();
    });
});
