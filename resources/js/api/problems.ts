import i18next from 'i18next';

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
