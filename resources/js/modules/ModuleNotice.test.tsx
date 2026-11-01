import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { apiFetch } from '../api/client';
import { ApiProblem } from '../api/problems';
import { queryKeys } from '../api/types';
import { appRoutes, moduleRoutes } from '../router';
import { renderRoutes } from '../test/routing';
import { loadModules } from './load';
import { resetModules } from './registry';

vi.mock('../api/client', () => ({ apiFetch: vi.fn(), onSessionExpired: vi.fn(() => () => {}), onTwoFactorRequired: vi.fn(() => () => {}), onStepUpRequired: vi.fn(() => () => {}), ensureCsrf: vi.fn() }));

const ADMIN = { id: 1, name: 'Anna Admin', email: 'anna@example.org', is_server_admin: true, locale: 'en', two_factor_required: false, second_factor: true, modules: [] };

let consoleError: ReturnType<typeof vi.spyOn>;

function serve(me: unknown) {
    vi.mocked(apiFetch).mockImplementation((path: string) => {
        if (path === '/me') return Promise.resolve(me);
        if (path === '/portal/me') return Promise.reject(new ApiProblem({ status: 401, title: 'Unauthorized' }));
        return Promise.resolve({ data: [] });
    });
}

beforeEach(async () => {
    resetModules();
    vi.mocked(apiFetch).mockReset();
    consoleError = vi.spyOn(console, 'error').mockImplementation(() => {});
    const entry = { name: 'example', route: '/example', entry: '/modules/example/entry.js?v=1.0.0' };
    await loadModules([entry], { timeoutMs: 5000, importer: () => Promise.reject(new TypeError('Failed to fetch dynamically imported module')) });
});

afterEach(() => consoleError.mockRestore());

describe('ModuleNotice', () => {
    it('names a module that failed to load to a server admin, and the console keeps working', async () => {
        serve(ADMIN);
        renderRoutes(appRoutes(moduleRoutes()), '/nowhere');
        expect(await screen.findByRole('link', { name: 'Back to the dashboard' })).toBeInTheDocument();
        expect(await screen.findByText("The example module couldn't be loaded. Check the browser console and `emailsquire:doctor`.")).toBeInTheDocument();
    });

    it('shows nothing to someone who is not a server admin', async () => {
        const manager = { ...ADMIN, is_server_admin: false };
        serve(manager);
        const { client } = renderRoutes(appRoutes(moduleRoutes()), '/nowhere');
        expect(await screen.findByRole('link', { name: 'Back to the dashboard' })).toBeInTheDocument();
        await waitFor(() => expect(client.getQueryData(queryKeys.me)).toEqual(manager));
        expect(screen.queryByText(/couldn't be loaded/)).not.toBeInTheDocument();
    });
});
