import { useQuery } from '@tanstack/react-query';
import { apiFetch } from '../api/client';
import { queryKeys, type Json } from '../api/types';
import { fetchMe, mustSetUpTwoFactor } from '../features/auth/useMe';

export type DomainName = Json<'/domains/names', 'get'>['data'][number];
export type Dashboard = Json<'/dashboard', 'get'>;

/**
 * False while the user must set up two-factor first: the server answers 403 to everything the layout
 * shows, so its queries wait instead of failing. Reads the cached `me` without fetching it.
 */
function useConsoleOpen(): boolean {
    const me = useQuery({ queryKey: queryKeys.me, queryFn: fetchMe, enabled: false, staleTime: Infinity, retry: false });
    return !mustSetUpTwoFactor(me.data);
}

export function useDomainNames() {
    const open = useConsoleOpen();
    return useQuery({
        enabled: open,
        queryKey: queryKeys.domainNames,
        queryFn: () => apiFetch<Json<'/domains/names', 'get'>>('/domains/names'),
        select: (r) => r.data,
    });
}

/** The requester's role on `domain`, from the names list; undefined until it loads or when the domain isn't theirs. */
export function useDomainRole(domain: string): DomainName['role'] | undefined {
    return useDomainNames().data?.find((d) => d.name === domain)?.role;
}

export function usePinnedDomains() {
    const open = useConsoleOpen();
    return useQuery({
        enabled: open,
        queryKey: queryKeys.pinned,
        queryFn: () => apiFetch<Json<'/me/pinned-domains', 'get'>>('/me/pinned-domains'),
        select: (r) => r.data,
    });
}

export function useDashboard() {
    const open = useConsoleOpen();
    return useQuery({ enabled: open, queryKey: queryKeys.dashboard, queryFn: () => apiFetch<Dashboard>('/dashboard') });
}

/** How many console users there are, for the sidebar's Users count. Server admins only: pass `enabled`. */
export function useUserCount(enabled: boolean) {
    const open = useConsoleOpen();
    return useQuery({
        enabled: open && enabled,
        queryKey: queryKeys.userCount,
        queryFn: () => apiFetch<Json<'/users', 'get'>>('/users', { query: { per_page: 1 } }),
        // A count in the sidebar can be a minute old; any users write invalidates it anyway.
        staleTime: 60_000,
        select: (r) => r.meta.total,
    });
}
