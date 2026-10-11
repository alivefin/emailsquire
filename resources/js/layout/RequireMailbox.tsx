import { useEffect, useRef, useState, type ReactNode } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Navigate, Outlet, useLocation } from 'react-router';
import { onSessionExpired } from '../api/client';
import { ApiProblem, problemMessage } from '../api/problems';
import { Button } from '../components/ui/Button';
import { useMe } from '../features/auth/useMe';
import { usePortalMe } from '../features/portal/usePortal';
import { PageSpinner } from './PageSpinner';
import { signInPath } from './RequireAuth';

/**
 * Renders its children (or the child routes) only for a mailbox owner's portal session: the `/my` area.
 *
 * The URL decides which session is asked about first, so the usual case costs one request: here
 * `/portal/me`. Only when it says no does the guard ask the console's `/me`, and a console session goes to
 * `/`: the portal is never drawn for it. No session at all goes to /signin with `next`. A 401 from any later
 * portal call (the mailbox was deleted or disabled, the session timed out, or a password change elsewhere
 * ended it) clears the cache and does the same with the "session expired" banner.
 */
export function RequireMailbox({ children }: { children?: ReactNode }) {
    const { t } = useTranslation(['portal', 'errors']);
    const portal = usePortalMe();
    const consoleMe = useMe(portal.data === null);
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
    if (portal.isPending) return <PageSpinner />;
    if (portal.isError && portal.data === undefined) {
        return (
            <div className="grid min-h-dvh place-items-center bg-canvas p-6">
                <div className="max-w-sm text-center">
                    <p role="alert" className="text-sm text-body">
                        {portal.error instanceof ApiProblem ? problemMessage(portal.error) : t('errors:unreachable')}
                    </p>
                    <Button className="mt-4" onClick={() => void portal.refetch()}>
                        {t('portal:layout.tryAgain')}
                    </Button>
                </div>
            </div>
        );
    }
    if (portal.data === null) {
        if (consoleMe.data) return <Navigate to="/" replace />;
        if (consoleMe.isFetching) return <PageSpinner />;
        return <Navigate to={signInPath(here)} replace />;
    }

    return children ?? <Outlet />;
}
