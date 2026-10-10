import type { QueryClient } from '@tanstack/react-query';
import { onAccessRefused } from './client';
import { queryKeys } from './types';

/**
 * After a domain write is refused (403 `forbidden` or 404), refetch what carries the user's roles: the
 * domain names (the role on each), the domain lists and everything under them, the pins and the
 * dashboard. Controls a stale role still showed then drop at once. Returns the unsubscribe function.
 */
export function refreshAccessOnRefusal(client: QueryClient): () => void {
    return onAccessRefused(() => {
        void Promise.all([
            client.invalidateQueries({ queryKey: ['domains'] }),
            client.invalidateQueries({ queryKey: queryKeys.pinned }),
            client.invalidateQueries({ queryKey: queryKeys.dashboard }),
        ]);
    });
}
