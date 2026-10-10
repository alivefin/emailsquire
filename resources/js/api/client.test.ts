import { afterEach, beforeEach, describe, it, expect, vi } from 'vitest';
import { ApiProblem, isStepUpCancelled } from './problems';
import { apiFetch, onSessionExpired, onStepUpRequired, onTwoFactorRequired } from './client';
import { setLocale } from '../i18n';

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
    it('sends Accept-Language for the current UI language', async () => {
        fetchMock.mockResolvedValueOnce(ok()).mockResolvedValueOnce(ok());
        await apiFetch('/domains');
        expect((fetchMock.mock.calls[0]![1] as RequestInit).headers).toMatchObject({ 'Accept-Language': 'en' });
        await setLocale('fi');
        await apiFetch('/domains');
        expect((fetchMock.mock.calls[1]![1] as RequestInit).headers).toMatchObject({ 'Accept-Language': 'fi' });
    });

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

const stepUp = () => problem(423, { code: 'step_up_required' });

describe('apiFetch step-up (423 step_up_required)', () => {
    it('awaits the handler and retries the request once when it resolves true', async () => {
        const handler = vi.fn(() => Promise.resolve(true));
        const off = onStepUpRequired(handler);
        fetchMock.mockResolvedValueOnce(stepUp()).mockResolvedValueOnce(ok({ done: 1 }));
        await expect(apiFetch('/domains/x.com', { method: 'DELETE', json: { confirm_name: 'x.com' } })).resolves.toEqual({
            done: 1,
        });
        expect(handler).toHaveBeenCalledTimes(1);
        expect(urls()).toEqual(['DELETE /api/v1/domains/x.com', 'DELETE /api/v1/domains/x.com']);
        expect((fetchMock.mock.calls[1]![1] as RequestInit).body).toBe('{"confirm_name":"x.com"}');
        off();
    });

    it('throws the original problem without retrying when the handler resolves false', async () => {
        const off = onStepUpRequired(() => Promise.resolve(false));
        fetchMock.mockResolvedValueOnce(stepUp());
        await expect(apiFetch('/domains/x.com', { method: 'DELETE' })).rejects.toMatchObject({
            status: 423,
            code: 'step_up_required',
        });
        expect(fetchMock).toHaveBeenCalledTimes(1);
        off();
    });

    it('throws when no handler is registered', async () => {
        fetchMock.mockResolvedValueOnce(stepUp());
        await expect(apiFetch('/domains/x.com', { method: 'DELETE' })).rejects.toMatchObject({ status: 423 });
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('stops calling a handler after its unsubscribe', async () => {
        const handler = vi.fn(() => Promise.resolve(true));
        onStepUpRequired(handler)();
        fetchMock.mockResolvedValueOnce(stepUp());
        await expect(apiFetch('/domains/x.com', { method: 'DELETE' })).rejects.toMatchObject({ status: 423 });
        expect(handler).not.toHaveBeenCalled();
    });

    it('throws a second 423 on the retry without asking again', async () => {
        const handler = vi.fn(() => Promise.resolve(true));
        const off = onStepUpRequired(handler);
        fetchMock.mockResolvedValueOnce(stepUp()).mockResolvedValueOnce(stepUp());
        await expect(apiFetch('/domains/x.com', { method: 'DELETE' })).rejects.toMatchObject({ status: 423 });
        expect(handler).toHaveBeenCalledTimes(1);
        expect(fetchMock).toHaveBeenCalledTimes(2);
        off();
    });

    it('shares one pending confirmation between parallel 423s and retries each request', async () => {
        let confirm: (ok: boolean) => void = () => {};
        const handler = vi.fn(
            () =>
                new Promise<boolean>((resolve) => {
                    confirm = resolve;
                }),
        );
        const off = onStepUpRequired(handler);
        fetchMock.mockImplementation((url: string) => {
            const n = fetchMock.mock.calls.filter((c) => c[0] === url).length;
            return Promise.resolve(n === 1 ? stepUp() : ok({ url }));
        });
        const a = apiFetch('/domains/a.com', { method: 'DELETE' });
        const b = apiFetch('/domains/b.com', { method: 'DELETE' });
        await vi.waitFor(() => expect(handler).toHaveBeenCalledTimes(1));
        // Let the second 423 arrive while the first confirmation is still open.
        await new Promise((r) => setTimeout(r, 0));
        confirm(true);
        await expect(a).resolves.toEqual({ url: '/api/v1/domains/a.com' });
        await expect(b).resolves.toEqual({ url: '/api/v1/domains/b.com' });
        expect(handler).toHaveBeenCalledTimes(1);
        expect(fetchMock).toHaveBeenCalledTimes(4);
        off();
    });

    it('asks again for a later 423 once the earlier confirmation is over', async () => {
        const handler = vi.fn(() => Promise.resolve(false));
        const off = onStepUpRequired(handler);
        fetchMock.mockResolvedValueOnce(stepUp()).mockResolvedValueOnce(stepUp());
        await expect(apiFetch('/a', { method: 'POST' })).rejects.toMatchObject({ status: 423 });
        await expect(apiFetch('/b', { method: 'POST' })).rejects.toMatchObject({ status: 423 });
        expect(handler).toHaveBeenCalledTimes(2);
        off();
    });

    it('treats a handler that rejects as cancelled', async () => {
        const off = onStepUpRequired(() => Promise.reject(new Error('gone')));
        fetchMock.mockResolvedValueOnce(stepUp());
        await expect(apiFetch('/a', { method: 'POST' })).rejects.toMatchObject({ status: 423 });
        off();
    });

    it('does not open step-up for a 423 with another code', async () => {
        const handler = vi.fn(() => Promise.resolve(true));
        const off = onStepUpRequired(handler);
        fetchMock.mockResolvedValueOnce(problem(423, { code: 'locked' }));
        await expect(apiFetch('/a', { method: 'POST' })).rejects.toMatchObject({ status: 423, code: 'locked' });
        expect(handler).not.toHaveBeenCalled();
        off();
    });

    it('retries without asking when a confirmation finished after the request went out', async () => {
        let release: (r: Response) => void = () => {};
        const handler = vi.fn(() => Promise.resolve(true));
        const off = onStepUpRequired(handler);
        // Request b is sent, then a 423s and is confirmed, then b's (stale) 423 arrives.
        fetchMock
            .mockImplementationOnce(() => new Promise<Response>((r) => (release = r)))
            .mockResolvedValueOnce(stepUp())
            .mockResolvedValueOnce(ok({ a: 1 }))
            .mockResolvedValueOnce(ok({ b: 1 }));
        const b = apiFetch('/b', { method: 'POST' });
        await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
        await expect(apiFetch('/a', { method: 'POST' })).resolves.toEqual({ a: 1 });
        release(stepUp());
        await expect(b).resolves.toEqual({ b: 1 });
        expect(handler).toHaveBeenCalledTimes(1);
        off();
    });
});

describe('apiFetch step-up cancel flag', () => {
    const caught = (path: string, init: Parameters<typeof apiFetch>[1] = { method: 'POST' }) =>
        apiFetch(path, init).then(
            () => {
                throw new Error('expected a rejection');
            },
            (e: unknown) => e,
        );

    it('marks the problem as cancelled when the user closes the dialog', async () => {
        const off = onStepUpRequired(() => Promise.resolve(false));
        fetchMock.mockResolvedValueOnce(stepUp());
        const e = await caught('/a');
        expect(e).toMatchObject({ status: 423, code: 'step_up_required', cancelled: true });
        expect(isStepUpCancelled(e)).toBe(true);
        off();
    });

    it('marks the problem as cancelled when the request was aborted', async () => {
        const off = onStepUpRequired(() => Promise.resolve(true));
        const ac = new AbortController();
        fetchMock.mockImplementationOnce(() => {
            ac.abort();
            return Promise.resolve(stepUp());
        });
        expect(isStepUpCancelled(await caught('/a', { signal: ac.signal }))).toBe(true);
        off();
    });

    it('a second 423 on the retry is a plain error, not a cancel', async () => {
        const off = onStepUpRequired(() => Promise.resolve(true));
        fetchMock.mockResolvedValueOnce(stepUp()).mockResolvedValueOnce(stepUp());
        const e = await caught('/a');
        expect(e).toMatchObject({ status: 423, code: 'step_up_required', cancelled: false });
        expect(isStepUpCancelled(e)).toBe(false);
        off();
    });

    it('a 423 nobody can ask about (no dialog registered) is a plain error, not a cancel', async () => {
        // Registering and at once unregistering leaves no handler, whatever an earlier test left.
        onStepUpRequired(() => Promise.resolve(true))();
        fetchMock.mockResolvedValueOnce(stepUp());
        const e = await caught('/a');
        expect(e).toMatchObject({ status: 423, code: 'step_up_required', cancelled: false });
        expect(isStepUpCancelled(e)).toBe(false);
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });
});

describe('apiFetch step-up with an aborted request', () => {
    it('neither asks nor retries when the request was aborted before its 423 arrived', async () => {
        const handler = vi.fn(() => Promise.resolve(true));
        const off = onStepUpRequired(handler);
        const ac = new AbortController();
        fetchMock.mockImplementationOnce(() => {
            ac.abort();
            return Promise.resolve(stepUp());
        });
        await expect(apiFetch('/me/recovery-codes', { signal: ac.signal })).rejects.toMatchObject({
            status: 423,
            code: 'step_up_required',
        });
        expect(handler).not.toHaveBeenCalled();
        expect(fetchMock).toHaveBeenCalledTimes(1);
        off();
    });

    it('stops waiting, cancels the dialog and does not retry when the only waiting request aborts', async () => {
        let dialogSignal: AbortSignal | undefined;
        let confirm: (ok: boolean) => void = () => {};
        const off = onStepUpRequired((signal) => {
            dialogSignal = signal;
            return new Promise<boolean>((resolve) => {
                confirm = resolve;
                signal.addEventListener('abort', () => resolve(false));
            });
        });
        const ac = new AbortController();
        fetchMock.mockResolvedValueOnce(stepUp());
        const request = apiFetch('/me/two-factor/setup', { signal: ac.signal });
        await vi.waitFor(() => expect(dialogSignal).toBeDefined());

        ac.abort();

        await expect(request).rejects.toMatchObject({ status: 423, code: 'step_up_required' });
        expect(dialogSignal!.aborted).toBe(true);
        confirm(true);
        await new Promise((r) => setTimeout(r, 0));
        expect(fetchMock).toHaveBeenCalledTimes(1);
        off();
    });

    it('keeps the dialog for the requests still waiting when one of them aborts', async () => {
        let dialogSignal: AbortSignal | undefined;
        let confirm: (ok: boolean) => void = () => {};
        const handler = vi.fn((signal: AbortSignal) => {
            dialogSignal = signal;
            return new Promise<boolean>((resolve) => (confirm = resolve));
        });
        const off = onStepUpRequired(handler);
        fetchMock.mockImplementation((url: string) => {
            const n = fetchMock.mock.calls.filter((c) => c[0] === url).length;
            return Promise.resolve(n === 1 ? stepUp() : ok({ url }));
        });
        const ac = new AbortController();
        const gone = apiFetch('/a', { signal: ac.signal });
        const stays = apiFetch('/b', { signal: new AbortController().signal });
        await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
        await new Promise((r) => setTimeout(r, 0));

        ac.abort();
        await expect(gone).rejects.toMatchObject({ status: 423 });
        expect(dialogSignal!.aborted).toBe(false);

        confirm(true);
        await expect(stays).resolves.toEqual({ url: '/api/v1/b' });
        expect(handler).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls.filter((c) => c[0] === '/api/v1/a')).toHaveLength(1);
        off();
    });

    it('does not retry a request that aborted while the confirmation was finishing', async () => {
        const ac = new AbortController();
        const off = onStepUpRequired(() => {
            ac.abort();
            return Promise.resolve(true);
        });
        fetchMock.mockResolvedValueOnce(stepUp());
        await expect(apiFetch('/a', { signal: ac.signal })).rejects.toMatchObject({ status: 423 });
        expect(fetchMock).toHaveBeenCalledTimes(1);
        off();
    });
});

describe('apiFetch two-factor required (403 two_factor_required)', () => {
    it('calls every subscriber, then throws', async () => {
        const one = vi.fn();
        const two = vi.fn();
        const offOne = onTwoFactorRequired(one);
        const offTwo = onTwoFactorRequired(two);
        fetchMock.mockResolvedValueOnce(problem(403, { code: 'two_factor_required' }));
        await expect(apiFetch('/domains')).rejects.toMatchObject({ status: 403, code: 'two_factor_required' });
        expect(one).toHaveBeenCalledTimes(1);
        expect(two).toHaveBeenCalledTimes(1);
        offOne();
        fetchMock.mockResolvedValueOnce(problem(403, { code: 'two_factor_required' }));
        await expect(apiFetch('/domains')).rejects.toBeInstanceOf(ApiProblem);
        expect(one).toHaveBeenCalledTimes(1);
        expect(two).toHaveBeenCalledTimes(2);
        offTwo();
    });

    it('does not call the subscribers for another 403', async () => {
        const cb = vi.fn();
        const off = onTwoFactorRequired(cb);
        fetchMock.mockResolvedValueOnce(problem(403, { code: 'cross_origin' }));
        await expect(apiFetch('/domains')).rejects.toMatchObject({ status: 403 });
        expect(cb).not.toHaveBeenCalled();
        off();
    });
});
