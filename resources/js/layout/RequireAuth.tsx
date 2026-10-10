import { useEffect, useRef, useState, type ReactNode } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Navigate, Outlet, useLocation } from 'react-router';
import { onSessionExpired } from '../api/client';
import { ApiProblem, problemMessage } from '../api/problems';
import { Button } from '../components/ui/Button';
import { useMe } from '../features/auth/useMe';
import { PageSpinner } from './PageSpinner';

export function signInPath(next: string, expired = false): string {
    return `/signin?next=${encodeURIComponent(next)}${expired ? '&expired=1' : ''}`;
}

/**
 * Renders its children (or the child routes) only for a signed-in admin.
 * No session sends the visitor to /signin with `next`; a 401 from any later call
 * clears the cache and does the same with the "session expired" banner.
 */
export function RequireAuth({ children }: { children?: ReactNode }) {
    const { t } = useTranslation(['common', 'errors']);
    const me = useMe();
    const location = useLocation();
    const queryClient = useQueryClient();
    const here = location.pathname + location.search;

    const hereRef = useRef(here);
    const expiredRef = useRef(false);
    const [expiredTo, setExpiredTo] = useState<string | null>(null);

    useEffect(() => {
        hereRef.current = here;
    }, [here]);

    useEffect(
        () =>
            onSessionExpired(() => {
                // Parallel queries that 401 together call this once each: act on the first only.
                if (expiredRef.current) return;
                expiredRef.current = true;
                queryClient.clear();
                setExpiredTo(signInPath(hereRef.current, true));
            }),
        [queryClient],
    );

    if (expiredTo !== null) return <Navigate to={expiredTo} replace />;
    if (me.isPending) return <PageSpinner />;
    if (me.isError) {
        return (
            <div className="grid min-h-dvh place-items-center bg-canvas p-6">
                <div className="max-w-sm text-center">
                    <p role="alert" className="text-sm text-body">
                        {me.error instanceof ApiProblem
                            ? problemMessage(me.error)
                            : t('errors:unreachable')}
                    </p>
                    <Button className="mt-4" onClick={() => void me.refetch()}>
                        {t('common:layout.tryAgain')}
                    </Button>
                </div>
            </div>
        );
    }
    if (me.data === null) return <Navigate to={signInPath(here)} replace />;

    return children ?? <Outlet />;
}
