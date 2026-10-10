export class ApiProblem extends Error {
    readonly status: number;
    readonly code: string | null;
    readonly title: string;
    readonly detail: string | null;
    readonly errors: Record<string, string[]>;

    constructor(init: {
        status: number;
        title: string;
        code?: string | null;
        detail?: string | null;
        errors?: Record<string, string[]>;
    }) {
        super(init.detail ?? init.title);
        this.name = 'ApiProblem';
        this.status = init.status;
        this.code = init.code ?? null;
        this.title = init.title;
        this.detail = init.detail ?? null;
        this.errors = init.errors ?? {};
    }
}

const MESSAGES: Record<string, string> = {
    busy: 'Someone else is changing this address. Try again.',
    archiving: 'This address is still being archived. Try again in a few minutes.',
    domain_exists: 'That domain already exists.',
    already_blocked: 'That address is already blocked.',
    cross_origin: 'This request came from another site and was refused.',
    csrf_mismatch: 'Your session changed. Reload the page and try again.',
    too_many_requests: 'Too many attempts. Wait a minute and try again.',
    usage_unavailable: 'Storage use is unavailable right now.',
};

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
        const known = MESSAGES[p.code];
        if (known !== undefined) return known;
    }
    if (p.status === 422) {
        const first = Object.values(fieldErrors(p))[0];
        if (first !== undefined) return first;
    }
    return p.detail ?? p.title;
}
