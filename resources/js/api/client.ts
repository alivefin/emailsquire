import { ApiProblem } from './problems';
import { currentLocale } from '../i18n';

type Method = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';

export interface ApiInit {
    method?: Method;
    json?: unknown;
    query?: Record<string, string | number | undefined>;
    signal?: AbortSignal;
}

const BASE = '/api/v1';
const expiredHandlers = new Set<() => void>();
const twoFactorHandlers = new Set<() => void>();

let stepUpHandler: (() => Promise<boolean>) | null = null;
// The confirmation in progress: parallel 423s wait for the same one instead of opening a dialog each.
let stepUpPending: Promise<boolean> | null = null;
// Counts successful confirmations, so a request sent before one finished retries without asking again.
let stepUpConfirmations = 0;

/** Register a callback for a 401 outside /auth/* and /me. Returns the unsubscribe function. */
export function onSessionExpired(cb: () => void): () => void {
    expiredHandlers.add(cb);
    return () => {
        expiredHandlers.delete(cb);
    };
}

/**
 * Register the step-up handler (the "Confirm it's you" dialog): on a 423 `step_up_required`, apiFetch
 * awaits it and retries the request once if it resolves `true`. One handler at a time; the latest wins.
 * Returns the unregister function.
 */
export function onStepUpRequired(handler: () => Promise<boolean>): () => void {
    stepUpHandler = handler;
    return () => {
        if (stepUpHandler === handler) stepUpHandler = null;
    };
}

/** Register a callback for a 403 `two_factor_required`. Returns the unsubscribe function. */
export function onTwoFactorRequired(cb: () => void): () => void {
    twoFactorHandlers.add(cb);
    return () => {
        twoFactorHandlers.delete(cb);
    };
}

/** Resolves `true` once the user has confirmed it is them, `false` when cancelled or nobody can ask. */
function confirmStepUp(): Promise<boolean> {
    if (stepUpPending !== null) return stepUpPending;
    const handler = stepUpHandler;
    if (handler === null) return Promise.resolve(false);
    const pending = handler()
        .then(
            (confirmed) => {
                if (confirmed) stepUpConfirmations++;
                return confirmed;
            },
            () => false,
        )
        .finally(() => {
            stepUpPending = null;
        });
    stepUpPending = pending;
    return pending;
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
        const headers: Record<string, string> = { Accept: 'application/json', 'Accept-Language': currentLocale() };
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

    const confirmationsBefore = stepUpConfirmations;
    let response = await send();
    const retryable = response.status === 419 || (response.status === 401 && method === 'POST' && path === '/auth/login');
    if (retryable) {
        await fetchCsrf();
        response = await send();
    }

    if (response.status === 423) {
        const problem = await toProblem(response);
        if (problem.code !== 'step_up_required') throw problem;
        // A confirmation that finished after this request went out already covers it.
        const confirmed = stepUpConfirmations !== confirmationsBefore || (await confirmStepUp());
        if (!confirmed) throw problem;
        // Once only: a second 423 falls through and throws without asking again.
        response = await send();
    }

    if (!response.ok) {
        const problem = await toProblem(response);
        if (response.status === 401 && !path.startsWith('/auth/') && path !== '/me') {
            for (const cb of [...expiredHandlers]) cb();
        }
        if (response.status === 403 && problem.code === 'two_factor_required') {
            for (const cb of [...twoFactorHandlers]) cb();
        }
        throw problem;
    }
    if (response.status === 204) return undefined as T;
    return (await response.json()) as T;
}
