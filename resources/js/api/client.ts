import { ApiProblem } from './problems';

type Method = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';

export interface ApiInit {
    method?: Method;
    json?: unknown;
    query?: Record<string, string | number | undefined>;
    signal?: AbortSignal;
}

const BASE = '/api/v1';
const expiredHandlers = new Set<() => void>();

/** Register a callback for a 401 outside /auth/* and /me. Returns the unsubscribe function. */
export function onSessionExpired(cb: () => void): () => void {
    expiredHandlers.add(cb);
    return () => {
        expiredHandlers.delete(cb);
    };
}

function xsrfToken(): string | null {
    for (const part of document.cookie.split('; ')) {
        if (part.startsWith('XSRF-TOKEN=')) {
            try {
                return decodeURIComponent(part.slice('XSRF-TOKEN='.length));
            } catch {
                return null;
            }
        }
    }
    return null;
}

async function fetchCsrf(): Promise<void> {
    await fetch(`${BASE}/auth/csrf`, {
        method: 'GET',
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    });
}

/** Makes sure the XSRF-TOKEN cookie exists; fetches it only when it is missing. */
export async function ensureCsrf(): Promise<void> {
    if (xsrfToken() === null) await fetchCsrf();
}

function buildUrl(path: string, query: ApiInit['query']): string {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(query ?? {})) {
        if (value !== undefined) params.append(key, String(value));
    }
    const qs = params.toString();
    return `${BASE}${path}${qs === '' ? '' : `?${qs}`}`;
}

async function toProblem(response: Response): Promise<ApiProblem> {
    let body: Record<string, unknown> = {};
    try {
        const parsed: unknown = await response.json();
        if (typeof parsed === 'object' && parsed !== null) body = parsed as Record<string, unknown>;
    } catch {
        // Not JSON: fall back to the status line.
    }
    return new ApiProblem({
        status: response.status,
        title: typeof body.title === 'string' ? body.title : response.statusText || `HTTP ${response.status}`,
        code: typeof body.code === 'string' ? body.code : null,
        detail: typeof body.detail === 'string' ? body.detail : null,
        errors: (body.errors as Record<string, string[]> | undefined) ?? {},
    });
}

export async function apiFetch<T>(path: string, init: ApiInit = {}): Promise<T> {
    const method = init.method ?? 'GET';
    const url = buildUrl(path, init.query);

    const send = (): Promise<Response> => {
        const headers: Record<string, string> = { Accept: 'application/json' };
        const token = xsrfToken();
        if (token !== null) headers['X-XSRF-TOKEN'] = token;
        let body: string | undefined;
        if (init.json !== undefined) {
            headers['Content-Type'] = 'application/json';
            body = JSON.stringify(init.json);
        }
        return fetch(url, { method, headers, body, credentials: 'same-origin', signal: init.signal });
    };

    if (method !== 'GET') await ensureCsrf();

    let response = await send();
    const retryable = response.status === 419 || (response.status === 401 && method === 'POST' && path === '/auth/login');
    if (retryable) {
        await fetchCsrf();
        response = await send();
    }

    if (!response.ok) {
        if (response.status === 401 && !path.startsWith('/auth/') && path !== '/me') {
            for (const cb of [...expiredHandlers]) cb();
        }
        throw await toProblem(response);
    }
    if (response.status === 204) return undefined as T;
    return (await response.json()) as T;
}
