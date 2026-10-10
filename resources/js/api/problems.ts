import i18next from 'i18next';

export class ApiProblem extends Error {
    readonly status: number;
    readonly code: string | null;
    readonly title: string;
    readonly detail: string | null;
    readonly errors: Record<string, string[]>;
    /** The invitation's state on a 410 `invitation_invalid` (`expired`, `used` or `revoked`); else null. */
    readonly state: string | null;
    /** apiFetch's mark on a 423 `step_up_required` the user cancelled (or whose request aborted). */
    readonly cancelled: boolean;

    constructor(init: {
        status: number;
        title: string;
        code?: string | null;
        detail?: string | null;
        errors?: Record<string, string[]>;
        state?: string | null;
        cancelled?: boolean;
    }) {
        super(init.detail ?? init.title);
        this.name = 'ApiProblem';
        this.status = init.status;
        this.code = init.code ?? null;
        this.title = init.title;
        this.detail = init.detail ?? null;
        this.errors = init.errors ?? {};
        this.state = init.state ?? null;
        this.cancelled = init.cancelled ?? false;
    }
}

export function fieldErrors(p: unknown): Record<string, string> {
    const out: Record<string, string> = {};
    if (!(p instanceof ApiProblem)) return out;
    for (const [field, messages] of Object.entries(p.errors)) {
        const first = messages[0];
        if (first !== undefined) out[field] = first;
    }
    return out;
}

export function problemMessage(p: ApiProblem): string {
    if (p.code !== null) {
        const key = `problem.${p.code}`;
        if (i18next.exists(key, { ns: 'errors' })) return i18next.t(key, { ns: 'errors' });
    }
    if (p.status === 422) {
        const first = Object.values(fieldErrors(p))[0];
        if (first !== undefined) return first;
    }
    return p.detail ?? p.title;
}

/**
 * The user closed the "Confirm it's you" dialog, or the request was aborted while it waited: apiFetch then
 * throws the original 423 marked `cancelled`. Callers treat it as a quiet cancel, with no error shown. Any
 * other 423 (one nobody could ask about, or a second one on the retry) is an error to show.
 */
export function isStepUpCancelled(e: unknown): boolean {
    return e instanceof ApiProblem && e.cancelled;
}
