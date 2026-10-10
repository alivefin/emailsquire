/**
 * What the console shows each role (plan 2c, the role matrix). The server enforces all of it; these only
 * decide which controls appear. A control a role can't use is hidden, not disabled, and stays hidden while
 * the role or `me` is still unknown.
 */

/** The requester's role on a domain, from the domain rows (`GET /domains`, `GET /domains/names`). */
export type DomainRole = 'admin' | 'manager' | 'viewer';

type Role = DomainRole | null | undefined;

interface MeFlags {
    is_server_admin: boolean;
    managers_can_share: boolean;
}

/** Mailbox and forwarding writes. */
export function canWrite(role: Role): boolean {
    return role === 'admin' || role === 'manager';
}

/** Sharing the domain (the Access tab's share controls): admins, and managers when the server allows it. */
export function canShare(role: Role, me: Pick<MeFlags, 'managers_can_share'> | null | undefined): boolean {
    if (role === 'admin') return true;
    return role === 'manager' && me?.managers_can_share === true;
}

/** The server-wide screens and controls: Users, blocked-address writes, server storage. */
export function isServerAdmin(me: Pick<MeFlags, 'is_server_admin'> | null | undefined): boolean {
    return me?.is_server_admin === true;
}

/** Adding and deleting domains, and changing their limits. */
export function canManageDomains(me: Pick<MeFlags, 'is_server_admin'> | null | undefined): boolean {
    return isServerAdmin(me);
}
