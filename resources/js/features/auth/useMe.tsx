import { useEffect } from 'react';
import { useQuery } from '@tanstack/react-query';
import { apiFetch } from '../../api/client';
import { ApiProblem } from '../../api/problems';
import { queryKeys, type Json } from '../../api/types';
import { currentLocale, isLocale, setLocale } from '../../i18n';

export type Me = Json<'/me', 'get'>;

export async function fetchMe(): Promise<Me | null> {
    try {
        return await apiFetch<Me>('/me');
    } catch (e) {
        if (e instanceof ApiProblem && e.status === 401) return null;
        throw e;
    }
}

/**
 * The signed-in admin, or `null` when there is no session. Never retries; a sign-in or sign-out sets it.
 * The admin's saved language wins over the browser's, so the UI switches to it once `me` is known.
 * `enabled: false` lets the portal's guard ask only once the portal's own `me` said no.
 */
export function useMe(enabled = true) {
    const query = useQuery({ queryKey: queryKeys.me, queryFn: fetchMe, retry: false, staleTime: Infinity, enabled });
    const locale = query.data?.locale;
    useEffect(() => {
        if (isLocale(locale) && locale !== currentLocale()) void setLocale(locale);
    }, [locale]);
    return query;
}

/**
 * True while the server refuses everything but My profile: the user's role needs two-factor
 * authentication and they have neither confirmed TOTP nor a usable passkey.
 */
export function mustSetUpTwoFactor(me: Me | null | undefined): boolean {
    return me?.two_factor_required === true && me.second_factor !== true;
}
