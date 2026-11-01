import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { apiFetch } from '../api/client';
import { ApiProblem } from '../api/problems';
import { appRoutes, moduleRoutes } from '../router';
import { renderRoutes } from '../test/routing';
import { expectModules, registerModule, resetModules } from './registry';

vi.mock('../api/client', () => ({ apiFetch: vi.fn(), onSessionExpired: vi.fn(() => () => {}), onTwoFactorRequired: vi.fn(() => () => {}), onStepUpRequired: vi.fn(() => () => {}), ensureCsrf: vi.fn() }));

const ME = { id: 1, name: 'Anna Admin', email: 'anna@example.org', is_server_admin: true, locale: 'en', two_factor_required: false, second_factor: true, modules: [] };

function Crash(): never {
    throw new Error('module bug');
}

let consoleError: ReturnType<typeof vi.spyOn>;

beforeEach(() => {
    resetModules();
    vi.mocked(apiFetch).mockReset();
    vi.mocked(apiFetch).mockImplementation((path: string) => {
        if (path === '/me') return Promise.resolve(ME);
        if (path === '/portal/me') return Promise.reject(new ApiProblem({ status: 401, title: 'Unauthorized' }));
        return Promise.resolve({ data: [] });
    });
    // React reports the error the boundary caught.
    consoleError = vi.spyOn(console, 'error').mockImplementation(() => {});
    expectModules([{ name: 'example', route: '/example' }]);
    registerModule({
        name: 'example',
        routes: [
            { path: '/example', element: <Crash /> },
            { path: '/example/fine', element: <p>Fine screen</p> },
        ],
        catalogues: { en: {}, fi: {} },
    });
});

afterEach(() => consoleError.mockRestore());

describe('ModuleBoundary', () => {
    it('shows an error panel for a crashing module screen and keeps the sidebar', async () => {
        renderRoutes(appRoutes(moduleRoutes()), '/example');
        expect(await screen.findByRole('heading', { name: "This page couldn't be shown" })).toBeInTheDocument();
        expect(screen.getByRole('navigation', { name: 'Main' })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Activity' })).toBeInTheDocument();
    });

    it('renders a working module screen inside the console layout', async () => {
        renderRoutes(appRoutes(moduleRoutes()), '/example/fine');
        expect(await screen.findByText('Fine screen')).toBeInTheDocument();
        expect(screen.getByRole('navigation', { name: 'Main' })).toBeInTheDocument();
    });

    it('clears the error panel after navigating away', async () => {
        expectModules([{ name: 'linked', route: '/linked' }]);
        let crash = true;
        function Sometimes() {
            if (crash) throw new Error('module bug');
            return <p>Recovered</p>;
        }
        registerModule({
            name: 'linked',
            routes: [
                {
                    path: '/linked',
                    element: <Sometimes />,
                    children: [{ path: 'b', element: null }],
                },
            ],
            catalogues: { en: {}, fi: {} },
        });
        const { router } = renderRoutes(appRoutes(moduleRoutes()), '/linked');
        expect(await screen.findByRole('heading', { name: "This page couldn't be shown" })).toBeInTheDocument();
        crash = false;
        await router.navigate('/linked/b');
        await waitFor(() => expect(screen.getByText('Recovered')).toBeInTheDocument());
    });

    it('keeps a module loader error inside the console layout', async () => {
        expectModules([{ name: 'loads', route: '/loads' }]);
        registerModule({
            name: 'loads',
            routes: [
                {
                    path: '/loads',
                    loader: () => {
                        throw new Error('loader bug');
                    },
                    element: <p>Never</p>,
                },
            ],
            catalogues: { en: {}, fi: {} },
        });
        renderRoutes(appRoutes(moduleRoutes()), '/loads');
        expect(await screen.findByRole('heading', { name: "This page couldn't be shown" })).toBeInTheDocument();
        expect(screen.getByRole('navigation', { name: 'Main' })).toBeInTheDocument();
        expect(screen.queryByText('Never')).not.toBeInTheDocument();
    });

    it('keeps a module\'s own error element for its loader errors', async () => {
        expectModules([{ name: 'own', route: '/own' }]);
        registerModule({
            name: 'own',
            routes: [
                {
                    path: '/own',
                    loader: () => {
                        throw new Error('loader bug');
                    },
                    ErrorBoundary: () => <p>Module error view</p>,
                },
            ],
            catalogues: { en: {}, fi: {} },
        });
        const consoleWarn = vi.spyOn(console, 'warn').mockImplementation(() => {});
        renderRoutes(appRoutes(moduleRoutes()), '/own');
        expect(await screen.findByText('Module error view')).toBeInTheDocument();
        // React Router warns when a route has both an ErrorBoundary and an errorElement.
        expect(consoleWarn).not.toHaveBeenCalled();
        consoleWarn.mockRestore();
    });
});
