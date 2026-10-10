import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { apiFetch } from '../api/client';
import { ApiProblem } from '../api/problems';
import { queryKeys } from '../api/types';
import { currentLocale, setLocale } from '../i18n';
import { renderRoutes, testQueryClient } from '../test/routing';
import { AccountMenu } from './AccountMenu';

vi.mock('../api/client', () => ({
    apiFetch: vi.fn(),
    onSessionExpired: vi.fn(() => () => {}),
    onTwoFactorRequired: vi.fn(() => () => {}), onStepUpRequired: vi.fn(() => () => {}),
    ensureCsrf: vi.fn(),
}));

const ME = { id: 1, name: 'Anna Admin', email: 'anna@example.org', is_server_admin: true, locale: 'en' as const };

beforeEach(() => {
    vi.mocked(apiFetch).mockReset();
});

describe('AccountMenu language switch', () => {
    it('saves the choice with PATCH /me, switches the language and updates the cached me', async () => {
        const user = userEvent.setup();
        const client = testQueryClient();
        client.setQueryData(queryKeys.me, ME);
        vi.mocked(apiFetch).mockResolvedValue({ ...ME, locale: 'fi' });
        renderRoutes([{ path: '/', element: <AccountMenu /> }], '/', client);

        await user.click(screen.getByRole('button', { name: 'Account menu' }));
        await user.click(screen.getByRole('menuitem', { name: 'Suomeksi' }));

        expect(apiFetch).toHaveBeenCalledWith('/me', { method: 'PATCH', json: { locale: 'fi' } });
        await waitFor(() => expect(currentLocale()).toBe('fi'));
        expect(client.getQueryData(queryKeys.me)).toEqual({ ...ME, locale: 'fi' });
        expect(document.documentElement.lang).toBe('fi');
    });

    it('keeps the language and the cached me when the save fails, and says so in a danger toast', async () => {
        const user = userEvent.setup();
        const client = testQueryClient();
        client.setQueryData(queryKeys.me, ME);
        vi.mocked(apiFetch).mockRejectedValue(new Error('offline'));
        renderRoutes([{ path: '/', element: <AccountMenu /> }], '/', client);

        await user.click(screen.getByRole('button', { name: 'Account menu' }));
        await user.click(screen.getByRole('menuitem', { name: 'Suomeksi' }));

        const toast = await screen.findByTestId('toast');
        expect(toast).toHaveTextContent('The language could not be changed.');
        expect(within(toast).getByTestId('toast-dot')).toHaveClass('bg-danger');
        expect(currentLocale()).toBe('en');
        expect(client.getQueryData(queryKeys.me)).toEqual(ME);
    });

    it('shows the problem message when the server refuses the change', async () => {
        const user = userEvent.setup();
        const client = testQueryClient();
        client.setQueryData(queryKeys.me, ME);
        vi.mocked(apiFetch).mockRejectedValue(
            new ApiProblem({ status: 500, title: 'Server Error', code: 'server_error', detail: 'Something went wrong.' }),
        );
        renderRoutes([{ path: '/', element: <AccountMenu /> }], '/', client);

        await user.click(screen.getByRole('button', { name: 'Account menu' }));
        await user.click(screen.getByRole('menuitem', { name: 'Suomeksi' }));

        const toast = await screen.findByTestId('toast');
        expect(toast).toHaveTextContent('Something went wrong.');
        expect(toast).not.toHaveTextContent('The language could not be changed.');
        expect(currentLocale()).toBe('en');
    });

    it('updates the cached me before it switches the language', async () => {
        const user = userEvent.setup();
        const client = testQueryClient();
        client.setQueryData(queryKeys.me, ME);
        vi.mocked(apiFetch).mockResolvedValue({ ...ME, locale: 'fi' });
        const seen: unknown[] = [];
        const unsubscribe = client.getQueryCache().subscribe(() => {
            seen.push([currentLocale(), client.getQueryData<typeof ME>(queryKeys.me)?.locale]);
        });
        renderRoutes([{ path: '/', element: <AccountMenu /> }], '/', client);

        await user.click(screen.getByRole('button', { name: 'Account menu' }));
        await user.click(screen.getByRole('menuitem', { name: 'Suomeksi' }));

        await waitFor(() => expect(currentLocale()).toBe('fi'));
        unsubscribe();
        // The cache already said fi while the console was still English.
        expect(seen).toContainEqual(['en', 'fi']);
        await setLocale('en');
    });
});

describe('AccountMenu role line', () => {
    it.each([
        [true, true],
        [false, false],
    ])('says Server admin only for a server admin (is_server_admin %s)', async (admin, shown) => {
        const user = userEvent.setup();
        const client = testQueryClient();
        client.setQueryData(queryKeys.me, { ...ME, is_server_admin: admin });
        renderRoutes([{ path: '/', element: <AccountMenu /> }], '/', client);

        await user.click(screen.getByRole('button', { name: 'Account menu' }));
        const menu = screen.getByRole('menu', { name: 'Account' });
        if (shown) expect(within(menu).getByText('Server admin')).toBeInTheDocument();
        else expect(within(menu).queryByText('Server admin')).not.toBeInTheDocument();
    });
});
