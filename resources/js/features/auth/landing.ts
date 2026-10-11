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

/** True for a path in the mailbox portal: `/my` itself or anything under it (`/mystery` is not). */
export function isPortalPath(path: string): boolean {
    const pathname = path.split(/[?#]/, 1)[0] ?? '';
    return pathname === '/my' || pathname.startsWith('/my/');
}

/**
 * Where a mailbox sign-in goes: `next` when it names a portal page, else the portal's home. A console page
 * in `next` is not for a mailbox owner, whose session can't open it.
 */
export function afterPortalSignInPath(rawNext: string | null): string {
    const next = safeNext(rawNext);
    return isPortalPath(next) ? next : '/my';
}

/** The shape of `me.landing`: a lowercase ASCII (punycode) domain name with at least one dot. */
const LANDING_DOMAIN = /^[a-z0-9-]+(\.[a-z0-9-]+)+$/;

/**
 * Where a sign-in goes: `next` when it names a page, else the only domain of a user who has exactly one
 * (`me.landing`), else the dashboard. A `next` of just `/` counts as none: the sign-in guard sends a bare
 * visit to the console there, and so does a portal page, which a console session can't open. Used by every
 * flow that ends signed in (sign-in, two-factor, setup link, invitation).
 */
export function afterSignInPath(me: { landing?: string | null } | null | undefined, rawNext: string | null = null): string {
    const next = safeNext(rawNext);
    // A console session never opens the portal: a portal page in `next` counts as none.
    if (next !== '/' && !isPortalPath(next)) return next;
    const landing = me?.landing;
    // An ASCII (punycode) domain name only: anything else, such as `..` or `.`, would leave the domain routes.
    return typeof landing === 'string' && LANDING_DOMAIN.test(landing) ? `/domains/${landing}/mailboxes` : '/';
}
