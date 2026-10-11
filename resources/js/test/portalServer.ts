import { ApiProblem } from '../api/problems';
import type { ApiInit } from '../api/client';
import type { PortalAliases, PortalForwarding, PortalMe } from '../features/portal/usePortal';

/** A signed-in mailbox owner as GET /portal/me answers: 1 GB of 5 GB used, forwarding allowed, aliases not. */
export const PORTAL_ME: PortalMe = {
    address: 'anna@example.org',
    domain: 'example.org',
    domain_unicode: 'example.org',
    quota_bytes: 5368709120,
    used_bytes: 1073741824,
    self_service: { forwarding: true, aliases: false, external_forwarding: false },
    managers: [{ name: 'Mia Manager', email: 'mia@example.net' }],
    support_contact: null,
    client_settings: {
        imap_host: 'imap.example.net',
        imap_port: 993,
        smtp_host: 'smtp.example.net',
        smtp_port: 587,
        webmail_url: 'https://webmail.example.net/',
    },
};

export const NO_FORWARDING: PortalForwarding = { targets: [], keep_copy: true };
export const NO_ALIASES: PortalAliases = { data: [] };

export const CONSOLE_ME = {
    id: 1,
    name: 'Anna Admin',
    email: 'anna@example.org',
    is_server_admin: true,
    locale: 'en',
    two_factor: { enabled: true, confirmed: true, recovery_codes_left: 8 },
    two_factor_required: false,
    managers_can_share: true,
    landing: null,
};

export const unauthenticated = () => new ApiProblem({ status: 401, title: 'Unauthorized', code: 'unauthenticated' });

type Handler = (init: ApiInit | undefined) => unknown;

/**
 * An apiFetch stand-in that answers by path. `session` picks who is signed in: the console's /me and the
 * portal's /portal/me answer 401 to the other kind, as the server's guards do. A handler may return a
 * value, a promise, or throw.
 */
export function portalApi(
    session: 'mailbox' | 'console' | 'none',
    handlers: Record<string, Handler> = {},
    me: PortalMe = PORTAL_ME,
) {
    const defaults: Record<string, Handler> = {
        '/portal/me': () => {
            if (session !== 'mailbox') throw unauthenticated();
            return me;
        },
        '/me': () => {
            if (session !== 'console') throw unauthenticated();
            return CONSOLE_ME;
        },
        '/portal/forwarding': () => NO_FORWARDING,
        '/portal/aliases': () => NO_ALIASES,
        '/auth/logout': () => undefined,
    };
    const all = { ...defaults, ...handlers };
    return (path: string, init?: ApiInit): Promise<unknown> => {
        const handler = all[path];
        if (handler === undefined) return Promise.reject(new Error(`unexpected ${path}`));
        try {
            return Promise.resolve(handler(init));
        } catch (e) {
            return Promise.reject(e);
        }
    };
}
