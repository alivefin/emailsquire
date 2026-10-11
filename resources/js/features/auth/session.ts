import type { QueryClient } from '@tanstack/react-query';
import { queryKeys } from '../../api/types';
import { apiFetch } from '../../api/client';
import type { Me } from './useMe';

/**
 * Drops everything an earlier session cached, console or portal, so nothing of another account or mailbox
 * can render under the new one. The `me` query itself stays (its data is replaced next), so a page that
 * watches it hears the change.
 */
export function forgetPreviousSession(queryClient: QueryClient): void {
    queryClient.removeQueries({ predicate: (q) => q.queryKey[0] !== queryKeys.me[0] });
    queryClient.getMutationCache().clear();
}

/** A console sign-in finished: only this admin's `me` is known, and there is no portal session. */
export function startConsoleSession(queryClient: QueryClient, me: Me): void {
    forgetPreviousSession(queryClient);
    queryClient.setQueryData(queryKeys.me, me);
    queryClient.setQueryData(queryKeys.portalMe, null);
}

/** After successful passkey authentication, read the complete /me schema (including capabilities). */
export async function loadConsoleSession(queryClient: QueryClient, signal: AbortSignal): Promise<Me | null> {
    forgetPreviousSession(queryClient);
    queryClient.setQueryData(queryKeys.me, null);
    queryClient.setQueryData(queryKeys.portalMe, null);
    const me = await apiFetch<Me>('/me', { signal });
    if (signal.aborted) return null;
    startConsoleSession(queryClient, me);
    return me;
}

/** A mailbox sign-in finished: no console `me`; the portal's guard loads the mailbox afresh. */
export function startPortalSession(queryClient: QueryClient): void {
    forgetPreviousSession(queryClient);
    queryClient.setQueryData(queryKeys.me, null);
}
