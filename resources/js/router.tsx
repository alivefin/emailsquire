import { createBrowserRouter, Navigate, type RouteObject } from 'react-router';
import { DashboardPage } from './features/dashboard/DashboardPage';
import { DomainPage } from './features/domain/DomainPage';
import { ForwardingTab } from './features/domain/ForwardingTab';
import { MailboxesTab } from './features/domain/MailboxesTab';
import { DomainsPage } from './features/domains/DomainsPage';
import { SetupPage } from './features/auth/SetupPage';
import { SignInPage } from './features/auth/SignInPage';
import { AppLayout, NotFound, Placeholder } from './layout/AppLayout';
import { RequireAuth } from './layout/RequireAuth';

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
                    { path: '/', element: <DashboardPage /> },
                    { path: '/domains', element: <DomainsPage /> },
                    {
                        path: '/domains/:domain',
                        element: <DomainPage />,
                        children: [
                            { index: true, element: <Navigate to="mailboxes" replace /> },
                            { path: 'mailboxes', element: <MailboxesTab /> },
                            { path: 'forwarding', element: <ForwardingTab /> },
                        ],
                    },
                    { path: '/addresses', element: <Placeholder title="All addresses" /> },
                    { path: '/blocked', element: <Placeholder title="Blocked addresses" /> },
                    { path: '*', element: <NotFound /> },
                ],
            },
        ],
    },
];

export const router = createBrowserRouter(routes);
