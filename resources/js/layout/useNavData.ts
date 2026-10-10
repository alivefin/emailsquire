import { useQuery } from '@tanstack/react-query';
import { apiFetch } from '../api/client';
import { queryKeys, type Json } from '../api/types';

export type DomainName = Json<'/domains/names', 'get'>['data'][number];
export type Dashboard = Json<'/dashboard', 'get'>;

export function useDomainNames() {
    return useQuery({
        queryKey: queryKeys.domainNames,
        queryFn: () => apiFetch<Json<'/domains/names', 'get'>>('/domains/names'),
        select: (r) => r.data,
    });
}

export function usePinnedDomains() {
    return useQuery({
        queryKey: queryKeys.pinned,
        queryFn: () => apiFetch<Json<'/me/pinned-domains', 'get'>>('/me/pinned-domains'),
        select: (r) => r.data,
    });
}

export function useDashboard() {
    return useQuery({ queryKey: queryKeys.dashboard, queryFn: () => apiFetch<Dashboard>('/dashboard') });
}
