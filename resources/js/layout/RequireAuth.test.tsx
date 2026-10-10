import { beforeEach, describe, expect, it, vi } from 'vitest';
import { act, screen } from '@testing-library/react';
import { apiFetch, onSessionExpired } from '../api/client';
import { ApiProblem } from '../api/problems';
import { renderRoutes, testQueryClient } from '../test/routing';
import { RequireAuth } from './RequireAuth';

vi.mock('../api/client', () => ({
    apiFetch: vi.fn(),
    onSessionExpired: vi.fn(() => () => {}),
    ensureCsrf: vi.fn(),
}));

const ME = { id: 1, name: 'Anna Admin', email: 'anna@example.org', is_server_admin: true };
const routes = [
    {
        element: <RequireAuth />,
        children: [{ path: '/domains/:name/mailboxes', element: <h1>Mailboxes</h1> }],
    },
];
const HERE = '/domains/example.org/mailboxes?q=jo&page=2';

beforeEach(() => {
    vi.mocked(apiFetch).mockReset();
    vi.mocked(onSessionExpired).mockReset();
    vi.mocked(onSessionExpired).mockImplementation(() => () => {});
});

function nextOf(location: string): string | null {
    return new URLSearchParams(location.slice(location.indexOf('?'))).get('next');
}

describe('RequireAuth', () => {
    it('shows a spinner while /me loads', () => {
        vi.mocked(apiFetch).mockReturnValue(new Promise(() => {}));
        renderRoutes(routes, HERE);

        expect(screen.getByRole('status', { name: 'Loading' })).toBeInTheDocument();
        expect(screen.queryByText('Mailboxes')).not.toBeInTheDocument();
    });

    it('renders the protected page for a signed-in admin', async () => {
        vi.mocked(apiFetch).mockResolvedValue(ME);
        renderRoutes(routes, HERE);

        expect(await screen.findByText('Mailboxes')).toBeInTheDocument();
    });

    it('redirects to /signin with next keeping the path and query string', async () => {
        vi.mocked(apiFetch).mockRejectedValue(new ApiProblem({ status: 401, title: 'Unauthorized' }));
        renderRoutes(routes, HERE);

        const location = (await screen.findByTestId('location')).textContent ?? '';
        expect(location.startsWith('/signin?')).toBe(true);
        expect(nextOf(location)).toBe(HERE);
        expect(location).not.toContain('expired=1');
    });

    it('on session expiry clears the cache once and goes to /signin with next and expired=1', async () => {
        let expire = () => {};
        vi.mocked(onSessionExpired).mockImplementation((cb) => {
            expire = cb;
            return () => {
                expire = () => {};
            };
        });
        vi.mocked(apiFetch).mockResolvedValue(ME);
        const client = testQueryClient();
        const clear = vi.spyOn(client, 'clear');
        client.setQueryData(['domains', {}], { data: [] });
        renderRoutes(routes, HERE, client);
        await screen.findByText('Mailboxes');

        vi.mocked(apiFetch).mockRejectedValue(new ApiProblem({ status: 401, title: 'Unauthorized' }));
        act(() => {
            // Several queries 401ing at once call the subscriber several times.
            expire();
            expire();
            expire();
        });

        const location = (await screen.findByTestId('location')).textContent ?? '';
        expect(location.startsWith('/signin?')).toBe(true);
        expect(nextOf(location)).toBe(HERE);
        expect(new URLSearchParams(location.slice(location.indexOf('?'))).get('expired')).toBe('1');
        expect(clear).toHaveBeenCalledTimes(1);
        expect(client.getQueryData(['domains', {}])).toBeUndefined();
    });
});
