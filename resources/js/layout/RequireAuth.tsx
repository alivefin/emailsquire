import { useEffect, useRef, useState, type ReactNode } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Navigate, Outlet, useLocation, useNavigate } from 'react-router';
import { onSessionExpired, onTwoFactorRequired } from '../api/client';
import { ApiProblem, problemMessage } from '../api/problems';
import { Button } from '../components/ui/Button';
import { mustSetUpTwoFactor, useMe } from '../features/auth/useMe';
import { PageSpinner } from './PageSpinner';

/** Where an admin who must turn on two-factor authentication finishes it (My profile). */
export const TWO_FACTOR_SETUP_PATH = '/profile#two-factor';

export function signInPath(next: string, expired = false): string {
    return `/signin?next=${encodeURIComponent(next)}${expired ? '&expired=1' : ''}`;
}

/**
 * Renders its children (or the child routes) only for a signed-in admin.
 * No session sends the visitor to /signin with `next`; a 401 from any later call
 * clears the cache and does the same with the "session expired" banner. A user who must set up
 * two-factor first (`me` says so, or a 403 `two_factor_required` arrives) goes to My profile's
 * two-factor section: the server refuses everything else until it is on.
 */
export function RequireAuth({ children }: { children?: ReactNode }) {
    const { t } = useTranslation(['common', 'errors']);
    const me = useMe();
    const location = useLocation();
    const queryClient = useQueryClient();
    const navigate = useNavigate();
    const here = location.pathname + location.search;

    const hereRef = useRef(here);
    const pathnameRef = useRef(location.pathname);
    const expiredRef = useRef(false);
    const [expiredTo, setExpiredTo] = useState<string | null>(null);

    useEffect(() => {
        hereRef.current = here;
        pathnameRef.current = location.pathname;
    }, [here, location.pathname]);

    useEffect(
        () =>
            onTwoFactorRequired(() => {
                // Several refused queries call this together; once on My profile, stay put.
                if (pathnameRef.current === '/profile') return;
                pathnameRef.current = '/profile';
                void navigate(TWO_FACTOR_SETUP_PATH, { replace: true });
            }),
        [navigate],
    );

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
    // Nothing but My profile answers until two-factor is set up: go there rather than wait for a 403.
    if (mustSetUpTwoFactor(me.data) && location.pathname !== '/profile') {
        return <Navigate to={TWO_FACTOR_SETUP_PATH} replace />;
    }

    return children ?? <Outlet />;
}
