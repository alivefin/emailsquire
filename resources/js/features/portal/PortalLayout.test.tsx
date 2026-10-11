import { beforeEach, describe, expect, it, vi } from 'vitest';
import { act, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { apiFetch, onSessionExpired } from '../../api/client';
import { queryKeys } from '../../api/types';
import { currentLocale } from '../../i18n';
import { RequireMailbox } from '../../layout/RequireMailbox';
import { PORTAL_ME, portalApi } from '../../test/portalServer';
import { renderRoutes, testQueryClient } from '../../test/routing';
import { PortalLayout } from './PortalLayout';

vi.mock('../../api/client', () => ({
    apiFetch: vi.fn(),
    onSessionExpired: vi.fn(() => () => {}),
    onTwoFactorRequired: vi.fn(() => () => {}),
    onStepUpRequired: vi.fn(() => () => {}),
    ensureCsrf: vi.fn(),
}));

const routes = [
    {
        path: '/my',
        element: (
            <RequireMailbox>
                <PortalLayout />
            </RequireMailbox>
        ),
        children: [
            { index: true, element: <h1>Portal home</h1> },
            { path: 'password', element: <h1>Portal password</h1> },
        ],
    },
];

beforeEach(() => {
    vi.mocked(apiFetch).mockReset();
    vi.mocked(onSessionExpired).mockReset();
    vi.mocked(onSessionExpired).mockImplementation(() => () => {});
});

describe('PortalLayout', () => {
    it('shows a spinner while the portal session is checked', () => {
        vi.mocked(apiFetch).mockReturnValue(new Promise(() => {}));
        renderRoutes(routes, '/my');

        expect(screen.getByRole('status', { name: 'Loading' })).toBeInTheDocument();
        expect(screen.queryByText('Portal home')).not.toBeInTheDocument();
    });

    it('shows the address, a language switch, Sign out and the six portal pages, and nothing of the console', async () => {
        vi.mocked(apiFetch).mockImplementation(portalApi('mailbox', {}, { ...PORTAL_ME, address: 'anna@xn--bcher-kva.example', domain_unicode: 'bücher.example' }));
        renderRoutes(routes, '/my');

        const header = await screen.findByRole('banner');
        expect(within(header).getByText('anna@bücher.example')).toBeInTheDocument();
        expect(within(header).getByRole('button', { name: 'Suomeksi' })).toBeInTheDocument();
        expect(within(header).getByRole('button', { name: 'Sign out' })).toBeInTheDocument();

        const nav = screen.getByRole('navigation', { name: 'Your mailbox' });
        const links = within(nav).getAllByRole('link');
        expect(links.map((a) => [a.textContent, a.getAttribute('href')])).toEqual([
            ['Home', '/my'],
            ['Password', '/my/password'],
            ['Forwarding', '/my/forwarding'],
            ['Aliases', '/my/aliases'],
            ['Settings', '/my/settings'],
            ['Help', '/my/help'],
        ]);
        expect(within(nav).getByRole('link', { name: 'Home' })).toHaveAttribute('aria-current', 'page');
        expect(screen.getByText('Portal home')).toBeInTheDocument();
        for (const word of [/domains?\b/i, /dashboard/i, /activity/i, /users/i, /admin/i]) {
            expect(document.body.textContent).not.toMatch(word);
        }
    });

    it('switches the language from the header and keeps it in the browser', async () => {
        vi.mocked(apiFetch).mockImplementation(portalApi('mailbox'));
        renderRoutes(routes, '/my/password');
        const user = userEvent.setup();

        await user.click(await screen.findByRole('button', { name: 'Suomeksi' }));

        expect(currentLocale()).toBe('fi');
        expect(window.localStorage.getItem('emailsquire.locale')).toBe('fi');
        expect(screen.getByRole('navigation', { name: 'Postilaatikkosi' })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Salasana' })).toHaveAttribute('aria-current', 'page');
        expect(screen.getByRole('button', { name: 'Kirjaudu ulos' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'In English' })).toBeInTheDocument();
        // No profile to save it to: nothing but the portal's own page was asked for.
        expect(vi.mocked(apiFetch).mock.calls.map(([p]) => p)).toEqual(['/portal/me']);
    });

    it('signs out, forgets the session and goes to the sign-in page', async () => {
        vi.mocked(apiFetch).mockImplementation(portalApi('mailbox'));
        const client = testQueryClient();
        renderRoutes(routes, '/my', client);
        const user = userEvent.setup();

        await user.click(await screen.findByRole('button', { name: 'Sign out' }));

        expect(await screen.findByTestId('location')).toHaveTextContent(/^\/signin$/);
        expect(apiFetch).toHaveBeenCalledWith('/auth/logout', { method: 'POST' });
        expect(client.getQueryData(queryKeys.portalMe)).toBeUndefined();
    });

    it('sends an ended session to sign in with the expired banner and the page as next', async () => {
        let expire = () => {};
        vi.mocked(onSessionExpired).mockImplementation((cb) => {
            expire = cb;
            return () => {
                expire = () => {};
            };
        });
        vi.mocked(apiFetch).mockImplementation(portalApi('mailbox'));
        renderRoutes(routes, '/my/password');
        await screen.findByText('Portal password');

        act(() => {
            expire();
            expire();
        });

        await waitFor(() => {
            const location = screen.getByTestId('location').textContent ?? '';
            expect(location).toBe('/signin?next=%2Fmy%2Fpassword&expired=1');
        });
    });

    it('offers Try again when the portal session cannot be checked', async () => {
        vi.mocked(apiFetch).mockRejectedValue(new Error('offline'));
        renderRoutes(routes, '/my');

        expect(await screen.findByText("Your mailbox couldn't be loaded.")).toBeInTheDocument();
        expect(screen.getByText('The server could not be reached.')).toBeInTheDocument();
        vi.mocked(apiFetch).mockImplementation(portalApi('mailbox'));
        await userEvent.setup().click(screen.getByRole('button', { name: 'Try again' }));
        expect(await screen.findByText('Portal home')).toBeInTheDocument();
    });
});
