import { beforeEach, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { QueryClientProvider } from '@tanstack/react-query';
import { createMemoryRouter, RouterProvider } from 'react-router';
import { apiFetch } from './api/client';
import { ApiProblem } from './api/problems';
import { ToastProvider } from './components/ui/Toast';
import { routes } from './router';
import { testQueryClient } from './test/routing';

vi.mock('./api/client', () => ({
    apiFetch: vi.fn(),
    onSessionExpired: vi.fn(() => () => {}),
    onTwoFactorRequired: vi.fn(() => () => {}),
    onStepUpRequired: vi.fn(() => () => {}),
    ensureCsrf: vi.fn(),
}));

function renderAt(path: string) {
    render(
        <QueryClientProvider client={testQueryClient()}>
            <ToastProvider>
                <RouterProvider router={createMemoryRouter(routes, { initialEntries: [path] })} />
            </ToastProvider>
        </QueryClientProvider>,
    );
}

beforeEach(() => {
    vi.mocked(apiFetch).mockReset();
});

it('renders the placeholder route for a signed-in admin', async () => {
    vi.mocked(apiFetch).mockResolvedValue({ id: 1, name: 'Anna', email: 'anna@example.org', is_server_admin: true });
    renderAt('/');
    expect(await screen.findByRole('heading', { name: 'Dashboard' })).toBeInTheDocument();
});

it('sends a signed-out visitor from / to the sign-in page', async () => {
    vi.mocked(apiFetch).mockRejectedValue(new ApiProblem({ status: 401, title: 'Unauthorized' }));
    renderAt('/');
    expect(await screen.findByRole('heading', { name: 'Sign in' })).toBeInTheDocument();
});

it('serves the setup page without signing in', () => {
    renderAt('/setup/tok123?email=anna%40example.org');
    expect(screen.getByRole('heading', { name: 'Set your password' })).toBeInTheDocument();
    expect(apiFetch).not.toHaveBeenCalled();
});

it('serves the forgot-password page without signing in', () => {
    renderAt('/forgot-password');
    expect(screen.getByRole('heading', { name: 'Reset your password' })).toBeInTheDocument();
    expect(apiFetch).not.toHaveBeenCalled();
});

it('serves the reset-password page without signing in', () => {
    renderAt('/reset-password/tok123?email=anna%40example.org');
    expect(screen.getByRole('heading', { name: 'Choose a new password' })).toBeInTheDocument();
    expect(apiFetch).not.toHaveBeenCalled();
});
