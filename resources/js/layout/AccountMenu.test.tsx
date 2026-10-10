import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { apiFetch } from '../api/client';
import { queryKeys } from '../api/types';
import { currentLocale } from '../i18n';
import { renderRoutes, testQueryClient } from '../test/routing';
import { AccountMenu } from './AccountMenu';

vi.mock('../api/client', () => ({
    apiFetch: vi.fn(),
    onSessionExpired: vi.fn(() => () => {}),
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

    it('keeps the language when the save fails', async () => {
        const user = userEvent.setup();
        const client = testQueryClient();
        client.setQueryData(queryKeys.me, ME);
        vi.mocked(apiFetch).mockRejectedValue(new Error('offline'));
        renderRoutes([{ path: '/', element: <AccountMenu /> }], '/', client);

        await user.click(screen.getByRole('button', { name: 'Account menu' }));
        await user.click(screen.getByRole('menuitem', { name: 'Suomeksi' }));

        await waitFor(() => expect(apiFetch).toHaveBeenCalled());
        expect(currentLocale()).toBe('en');
        expect(client.getQueryData(queryKeys.me)).toEqual(ME);
    });
});
