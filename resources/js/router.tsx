import { createBrowserRouter, Navigate, Outlet, type RouteObject } from 'react-router';
import { StepUpDialog } from './components/StepUpDialog';
import { AddressesPage } from './features/addresses/AddressesPage';
import { BlockedPage } from './features/blocked/BlockedPage';
import { DashboardPage } from './features/dashboard/DashboardPage';
import { DomainPage } from './features/domain/DomainPage';
import { ForwardingTab } from './features/domain/ForwardingTab';
import { MailboxesTab } from './features/domain/MailboxesTab';
import { DomainsPage } from './features/domains/DomainsPage';
import { ForgotPasswordPage } from './features/auth/ForgotPasswordPage';
import { ResetPasswordPage } from './features/auth/ResetPasswordPage';
import { SetupPage } from './features/auth/SetupPage';
import { SignInPage } from './features/auth/SignInPage';
import { AppLayout, NotFound } from './layout/AppLayout';
import { RequireAuth } from './layout/RequireAuth';

// The placeholder pages are replaced by the real screens in later tasks.
// The root element hosts the step-up dialog: it sits inside the router because it shares the modal
// machinery (useModal reads the location), and above every page so any request can ask for it.
export const routes: RouteObject[] = [
    {
        element: (
            <>
                <Outlet />
                <StepUpDialog />
            </>
        ),
        children: [
            { path: '/signin', element: <SignInPage /> },
            { path: '/setup/:token', element: <SetupPage /> },
            { path: '/forgot-password', element: <ForgotPasswordPage /> },
            { path: '/reset-password/:token', element: <ResetPasswordPage /> },
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
                            { path: '/addresses', element: <AddressesPage /> },
                            { path: '/blocked', element: <BlockedPage /> },
                            { path: '*', element: <NotFound /> },
                        ],
                    },
                ],
            },
        ],
    },
];

export const router = createBrowserRouter(routes);
