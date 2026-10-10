import { afterEach, beforeEach, describe, it, expect, vi } from 'vitest';
import { ApiProblem } from './problems';
import { apiFetch, onSessionExpired } from './client';

function problem(status: number, body: Record<string, unknown> = {}) {
    return new Response(JSON.stringify({ type: 'about:blank', title: 'T', status, ...body }), {
        status,
        headers: { 'Content-Type': 'application/problem+json' },
    });
}
const ok = (body: unknown = { ok: true }) => new Response(JSON.stringify(body), { status: 200 });

let fetchMock: ReturnType<typeof vi.fn>;
const clearCookie = () => {
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
};

beforeEach(() => {
    fetchMock = vi.fn();
    vi.stubGlobal('fetch', fetchMock);
    clearCookie();
    document.cookie = 'XSRF-TOKEN=a%3Db; path=/';
});
afterEach(() => {
    vi.unstubAllGlobals();
    clearCookie();
});

const urls = () => fetchMock.mock.calls.map((c) => `${(c[1] as RequestInit).method} ${c[0] as string}`);

describe('apiFetch', () => {
    it('sends the decoded XSRF cookie and JSON headers', async () => {
        fetchMock.mockResolvedValueOnce(ok());
        await apiFetch('/domains', { method: 'POST', json: { name: 'x.com' } });
        const init = fetchMock.mock.calls[0]![1] as RequestInit;
        expect(init.headers).toMatchObject({ 'X-XSRF-TOKEN': 'a=b', Accept: 'application/json', 'Content-Type': 'application/json' });
        expect(init.credentials).toBe('same-origin');
        expect(init.body).toBe('{"name":"x.com"}');
    });

    it('fetches the CSRF cookie before the first non-GET when it is missing', async () => {
        clearCookie();
        fetchMock.mockResolvedValueOnce(new Response(null, { status: 204 })).mockResolvedValueOnce(ok());
        await apiFetch('/domains', { method: 'POST', json: {} });
        expect(urls()).toEqual(['GET /api/v1/auth/csrf', 'POST /api/v1/domains']);
    });

    it('maps problem JSON to ApiProblem', async () => {
        fetchMock.mockResolvedValueOnce(
            problem(422, { title: 'Invalid', detail: 'D', code: 'validation', errors: { name: ['Bad'] } }),
        );
        const err = await apiFetch('/domains').catch((e: unknown) => e);
        expect(err).toBeInstanceOf(ApiProblem);
        expect(err).toMatchObject({ status: 422, title: 'Invalid', detail: 'D', code: 'validation', errors: { name: ['Bad'] } });
    });

    it('handles a non-JSON error body', async () => {
        fetchMock.mockResolvedValueOnce(new Response('oops', { status: 502, statusText: 'Bad Gateway' }));
        await expect(apiFetch('/domains')).rejects.toMatchObject({ status: 502, code: null, errors: {} });
    });

    it('retries once after a 419 with a CSRF fetch', async () => {
        fetchMock
            .mockResolvedValueOnce(problem(419, { code: 'csrf_mismatch' }))
            .mockResolvedValueOnce(new Response(null, { status: 204 }))
            .mockResolvedValueOnce(ok({ done: 1 }));
        await expect(apiFetch('/domains', { method: 'POST', json: {} })).resolves.toEqual({ done: 1 });
        expect(urls()).toEqual(['POST /api/v1/domains', 'GET /api/v1/auth/csrf', 'POST /api/v1/domains']);
    });

    it('throws on a second 419', async () => {
        fetchMock
            .mockResolvedValueOnce(problem(419, { code: 'csrf_mismatch' }))
            .mockResolvedValueOnce(new Response(null, { status: 204 }))
            .mockResolvedValueOnce(problem(419, { code: 'csrf_mismatch' }));
        await expect(apiFetch('/domains', { method: 'POST', json: {} })).rejects.toMatchObject({ status: 419 });
        expect(fetchMock).toHaveBeenCalledTimes(3);
    });

    it('calls the expired handler once on a 401 and throws', async () => {
        const cb = vi.fn();
        const off = onSessionExpired(cb);
        fetchMock.mockResolvedValueOnce(problem(401));
        await expect(apiFetch('/domains')).rejects.toMatchObject({ status: 401 });
        expect(cb).toHaveBeenCalledTimes(1);
        off();
        fetchMock.mockResolvedValueOnce(problem(401));
        await expect(apiFetch('/domains')).rejects.toBeInstanceOf(ApiProblem);
        expect(cb).toHaveBeenCalledTimes(1);
    });

    it('does not call the expired handler for /me or /auth/* 401s', async () => {
        const cb = vi.fn();
        const off = onSessionExpired(cb);
        fetchMock.mockResolvedValueOnce(problem(401));
        await expect(apiFetch('/me')).rejects.toBeInstanceOf(ApiProblem);
        fetchMock.mockResolvedValueOnce(problem(401));
        await expect(apiFetch('/auth/logout', { method: 'POST' })).rejects.toBeInstanceOf(ApiProblem);
        expect(cb).not.toHaveBeenCalled();
        off();
    });

    it('retries a login 401 once after a CSRF fetch without the expired handler', async () => {
        const cb = vi.fn();
        const off = onSessionExpired(cb);
        fetchMock
            .mockResolvedValueOnce(problem(401))
            .mockResolvedValueOnce(new Response(null, { status: 204 }))
            .mockResolvedValueOnce(ok({ id: 1 }));
        await expect(apiFetch('/auth/login', { method: 'POST', json: {} })).resolves.toEqual({ id: 1 });
        expect(urls()).toEqual(['POST /api/v1/auth/login', 'GET /api/v1/auth/csrf', 'POST /api/v1/auth/login']);
        expect(cb).not.toHaveBeenCalled();
        off();
    });

    it('throws when the login retry also 401s', async () => {
        fetchMock
            .mockResolvedValueOnce(problem(401))
            .mockResolvedValueOnce(new Response(null, { status: 204 }))
            .mockResolvedValueOnce(problem(401));
        await expect(apiFetch('/auth/login', { method: 'POST', json: {} })).rejects.toMatchObject({ status: 401 });
        expect(fetchMock).toHaveBeenCalledTimes(3);
    });

    it('resolves a 204 to undefined', async () => {
        fetchMock.mockResolvedValueOnce(new Response(null, { status: 204 }));
        await expect(apiFetch('/auth/logout', { method: 'POST' })).resolves.toBeUndefined();
    });

    it('drops undefined query values and encodes the rest', async () => {
        fetchMock.mockResolvedValueOnce(ok());
        await apiFetch('/search', { query: { q: 'a b&c', page: 2, skip: undefined } });
        expect(fetchMock.mock.calls[0]![0]).toBe('/api/v1/search?q=a+b%26c&page=2');
    });
});
