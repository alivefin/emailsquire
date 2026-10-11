import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClientProvider } from '@tanstack/react-query';
import { testQueryClient } from '../test/routing';
import { apiFetch, type ApiInit } from './client';
import { useAuditLog, type AuditQuery } from './auditLog';

vi.mock('./client', () => ({ apiFetch: vi.fn() }));

const META = { page: 1, per_page: 20, total: 1, last_page: 1 };

beforeEach(() => {
    vi.mocked(apiFetch).mockReset();
    // a.org answers at once; b.org never does, so the hook shows what it holds while loading.
    vi.mocked(apiFetch).mockImplementation(((_path: string, init?: ApiInit) =>
        init?.query?.domain === 'a.org' ? Promise.resolve({ data: [{ id: 1 }], meta: META }) : new Promise(() => {})) as typeof apiFetch);
});

function render(keepPrevious?: boolean) {
    const client = testQueryClient();
    const wrapper = ({ children }: { children: ReactNode }) => <QueryClientProvider client={client}>{children}</QueryClientProvider>;
    return renderHook(({ query }: { query: AuditQuery }) => useAuditLog(query, { keepPrevious }), {
        wrapper,
        initialProps: { query: { domain: 'a.org', per_page: 20 } },
    });
}

describe('useAuditLog', () => {
    it("shows nothing of the previous query's rows by default (a domain's tab)", async () => {
        const { result, rerender } = render();
        await waitFor(() => expect(result.current.data?.data).toEqual([{ id: 1 }]));
        rerender({ query: { domain: 'b.org', per_page: 20 } });
        expect(result.current.data).toBeUndefined();
        expect(result.current.isPending).toBe(true);
    });

    it('keeps the previous rows while the next page loads when asked (the activity log)', async () => {
        const { result, rerender } = render(true);
        await waitFor(() => expect(result.current.data?.data).toEqual([{ id: 1 }]));
        rerender({ query: { domain: 'b.org', per_page: 20 } });
        expect(result.current.data?.data).toEqual([{ id: 1 }]);
        expect(result.current.isPlaceholderData).toBe(true);
    });
});
