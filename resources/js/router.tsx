import { createBrowserRouter, Navigate, useParams, type RouteObject } from 'react-router';
import { SetupPage } from './features/auth/SetupPage';
import { SignInPage } from './features/auth/SignInPage';
import { AppLayout, NotFound, Placeholder } from './layout/AppLayout';
import { RequireAuth } from './layout/RequireAuth';

function DomainRedirect() {
    const { domain = '' } = useParams();
    return <Navigate to={`/domains/${domain}/mailboxes`} replace />;
}

// The placeholder pages are replaced by the real screens in later tasks.
export const routes: RouteObject[] = [
    { path: '/signin', element: <SignInPage /> },
    { path: '/setup/:token', element: <SetupPage /> },
    {
        element: <RequireAuth />,
        children: [
            {
                element: <AppLayout />,
                children: [
                    { path: '/', element: <Placeholder title="Dashboard" /> },
                    { path: '/domains', element: <Placeholder title="Domains" /> },
                    { path: '/domains/:domain', element: <DomainRedirect /> },
                    { path: '/domains/:domain/mailboxes', element: <Placeholder title="Mailboxes" /> },
                    { path: '/domains/:domain/forwarding', element: <Placeholder title="Forwarding" /> },
                    { path: '/addresses', element: <Placeholder title="All addresses" /> },
                    { path: '/blocked', element: <Placeholder title="Blocked addresses" /> },
                    { path: '*', element: <NotFound /> },
                ],
            },
        ],
    },
];

export const router = createBrowserRouter(routes);
