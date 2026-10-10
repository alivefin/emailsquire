/**
 * Returns `raw` when it is an internal path, else `/`. Rejects protocol-relative (`//`, `/\`) values,
 * control characters and backslashes (browsers strip or rewrite them), and `/signin` itself.
 */
export function safeNext(raw: string | null): string {
    if (raw === null || !raw.startsWith('/') || raw.startsWith('//')) return '/';
    for (const ch of raw) {
        const code = ch.charCodeAt(0);
        if (code < 0x20 || code === 0x7f || ch === '\\') return '/';
    }
    const path = raw.split(/[?#]/, 1)[0];
    if (path === '/signin' || path === '/signin/') return '/';
    return raw;
}

/**
 * Where a sign-in goes: `next` when it names a page, else the only domain of a user who has exactly one
 * (`me.landing`), else the dashboard. A `next` of just `/` counts as none: the sign-in guard sends a bare
 * visit to the console there. Used by every flow that ends signed in (sign-in, two-factor, setup link,
 * invitation).
 */
export function afterSignInPath(me: { landing?: string | null } | null | undefined, rawNext: string | null = null): string {
    const next = safeNext(rawNext);
    if (next !== '/') return next;
    const landing = me?.landing;
    return landing ? `/domains/${encodeURIComponent(landing)}/mailboxes` : '/';
}
