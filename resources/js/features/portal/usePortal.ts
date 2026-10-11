import { useQuery, type QueryClient } from '@tanstack/react-query';
import i18next from 'i18next';
import { apiFetch } from '../../api/client';
import { ApiProblem, problemMessage } from '../../api/problems';
import { queryKeys, type Json } from '../../api/types';

export type PortalMe = Json<'/portal/me', 'get'>;
export type PortalForwarding = Json<'/portal/forwarding', 'get'>;
export type PortalAliases = Json<'/portal/aliases', 'get'>;

/** The signed-in mailbox, or `null` when this browser has no portal session (a console one answers 401 too). */
export async function fetchPortalMe(): Promise<PortalMe | null> {
    try {
        return await apiFetch<PortalMe>('/portal/me');
    } catch (e) {
        if (e instanceof ApiProblem && e.status === 401) return null;
        throw e;
    }
}

/**
 * The portal's `me`: the mailbox, its storage, the domain's switches, who to ask and the mail-app settings.
 * `enabled: false` lets a console guard ask only once the console's own `me` said no. Storage use and the
 * switches can change while the portal is open, so a minute-old answer is refetched when a page mounts.
 */
export function usePortalMe(enabled = true) {
    return useQuery({ queryKey: queryKeys.portalMe, queryFn: fetchPortalMe, retry: false, staleTime: 60_000, enabled });
}

export function usePortalForwarding() {
    return useQuery({
        queryKey: queryKeys.portalForwarding,
        queryFn: ({ signal }) => apiFetch<PortalForwarding>('/portal/forwarding', { signal }),
    });
}

export function usePortalAliases() {
    return useQuery({
        queryKey: queryKeys.portalAliases,
        queryFn: ({ signal }) => apiFetch<PortalAliases>('/portal/aliases', { signal }),
    });
}

/** The address as people read it: the local part with the domain's Unicode name (`anna@bücher.example`). */
export function displayAddress(me: Pick<PortalMe, 'address' | 'domain_unicode'>): string {
    const at = me.address.lastIndexOf('@');
    return at < 0 ? me.address : `${me.address.slice(0, at)}@${me.domain_unicode}`;
}

/** The most forwarding targets and aliases an owner may have (spec decisions 6 and 7; the server's `SelfService`). */
export const MAX_TARGETS = 10;
export const MAX_ALIASES = 20;

/**
 * After a portal write, or a refusal saying a switch is now off: rereads `me` (the switches) and the
 * summaries Home shows.
 */
export async function refreshPortal(client: QueryClient): Promise<void> {
    await Promise.all([
        client.invalidateQueries({ queryKey: queryKeys.portalMe }),
        client.invalidateQueries({ queryKey: queryKeys.portalForwarding }),
        client.invalidateQueries({ queryKey: queryKeys.portalAliases }),
    ]);
}

/** The server said the domain's switch for this is off: the screen turns to its locked state. */
export function isSelfServiceDisabled(e: unknown): boolean {
    return e instanceof ApiProblem && e.code === 'self_service_disabled';
}

/** A failed portal write in words: the server's problem, or that it couldn't be reached. */
export function writeErrorText(e: unknown): string {
    return e instanceof ApiProblem ? problemMessage(e) : i18next.t('unreachableRetry', { ns: 'errors' });
}
