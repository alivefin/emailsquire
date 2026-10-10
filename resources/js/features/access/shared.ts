import type { QueryClient } from '@tanstack/react-query';
import type { Json } from '../../api/types';

export type AccessList = Json<'/domains/{domain}/access', 'get'>;
export type AccessPerson = AccessList['people'][number];
export type AccessInvitation = AccessList['invitations'][number];

/** The roles a grant or a domain invitation can give. */
export type GrantRole = AccessPerson['role'];

/** API path under a domain's access: `/domains/{d}/access`, then any further segments, each encoded. */
export function accessPath(domain: string, ...rest: (string | number)[]): string {
    return ['', 'domains', domain, 'access', ...rest.map(String)].map(encodeURIComponent).join('/');
}

/**
 * Refetches what a sharing write can change: the domain's access list (under `domains`) and the domain
 * rows, whose People column and counts list the people with a grant.
 */
export async function refreshAfterAccessWrite(client: QueryClient): Promise<void> {
    await client.invalidateQueries({ queryKey: ['domains'] });
}
