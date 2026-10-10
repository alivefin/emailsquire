import { useQuery } from '@tanstack/react-query';
import { apiFetch } from '../../api/client';
import { ApiProblem } from '../../api/problems';
import { queryKeys, type Json } from '../../api/types';

export type Me = Json<'/me', 'get'>;

async function fetchMe(): Promise<Me | null> {
    try {
        return await apiFetch<Me>('/me');
    } catch (e) {
        if (e instanceof ApiProblem && e.status === 401) return null;
        throw e;
    }
}

/** The signed-in admin, or `null` when there is no session. Never retries; a sign-in or sign-out sets it. */
export function useMe() {
    return useQuery({ queryKey: queryKeys.me, queryFn: fetchMe, retry: false, staleTime: Infinity });
}
