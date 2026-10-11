import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { apiFetch } from '../../api/client';
import { ApiProblem } from '../../api/problems';
import { currentLocale } from '../../i18n';
import { mustSetUpTwoFactor, useMe, type Me } from './useMe';

vi.mock('../../api/client', () => ({
    apiFetch: vi.fn(),
    onSessionExpired: vi.fn(() => () => {}),
    onTwoFactorRequired: vi.fn(() => () => {}), onStepUpRequired: vi.fn(() => () => {}),
    ensureCsrf: vi.fn(),
}));

const ME = { id: 1, name: 'Anna Admin', email: 'anna@example.org', is_server_admin: true, locale: 'en' as const };

function wrapper() {
    // Retries on by default, so the test proves useMe itself turns them off.
    const client = new QueryClient({ defaultOptions: { queries: { retry: 3, retryDelay: 0 } } });
    return function Wrapper({ children }: { children: ReactNode }) {
        return <QueryClientProvider client={client}>{children}</QueryClientProvider>;
    };
}

beforeEach(() => {
    vi.mocked(apiFetch).mockReset();
});

describe('useMe', () => {
    it('accepts a passkey-only admin as having the required second factor', () => {
        expect(mustSetUpTwoFactor({ ...ME, two_factor_required: true, second_factor: true,
            two_factor: { confirmed: false } } as Me)).toBe(false);
    });
    it('uses second_factor even if an old TOTP flag disagrees', () => {
        expect(mustSetUpTwoFactor({ ...ME, two_factor_required: true, second_factor: false,
            two_factor: { confirmed: true } } as Me)).toBe(true);
    });
    it('returns the signed-in admin', async () => {
        vi.mocked(apiFetch).mockResolvedValue(ME);
        const { result } = renderHook(() => useMe(), { wrapper: wrapper() });

        await waitFor(() => expect(result.current.data).toEqual(ME));
        expect(apiFetch).toHaveBeenCalledWith('/me');
    });

    it('maps a 401 to null without retrying', async () => {
        vi.mocked(apiFetch).mockRejectedValue(new ApiProblem({ status: 401, title: 'Unauthorized' }));
        const { result } = renderHook(() => useMe(), { wrapper: wrapper() });

        await waitFor(() => expect(result.current.isSuccess).toBe(true));
        expect(result.current.data).toBeNull();
        expect(apiFetch).toHaveBeenCalledTimes(1);
    });

    it('rethrows any other problem', async () => {
        const problem = new ApiProblem({ status: 500, title: 'Server Error' });
        vi.mocked(apiFetch).mockRejectedValue(problem);
        const { result } = renderHook(() => useMe(), { wrapper: wrapper() });

        await waitFor(() => expect(result.current.isError).toBe(true));
        expect(result.current.error).toBe(problem);
        expect(apiFetch).toHaveBeenCalledTimes(1);
    });

    it('switches the language to the signed-in admin\'s locale', async () => {
        vi.mocked(apiFetch).mockResolvedValue({ ...ME, locale: 'fi' });
        renderHook(() => useMe(), { wrapper: wrapper() });

        await waitFor(() => expect(currentLocale()).toBe('fi'));
    });
});
