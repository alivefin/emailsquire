import { createBrowserRouter, Navigate, Outlet, type RouteObject } from 'react-router';
import { StepUpDialog } from './components/StepUpDialog';
import { AccessTab } from './features/access/AccessTab';
import { AddressesPage } from './features/addresses/AddressesPage';
import { BlockedPage } from './features/blocked/BlockedPage';
import { DashboardPage } from './features/dashboard/DashboardPage';
import { DomainPage } from './features/domain/DomainPage';
import { ForwardingTab } from './features/domain/ForwardingTab';
import { MailboxesTab } from './features/domain/MailboxesTab';
import { DomainsPage } from './features/domains/DomainsPage';
import { ProfilePage } from './features/profile/ProfilePage';
import { UserDetailPage } from './features/users/UserDetailPage';
import { UsersPage } from './features/users/UsersPage';
import { ForgotPasswordPage } from './features/auth/ForgotPasswordPage';
import { InvitationPage } from './features/auth/InvitationPage';
import { ResetPasswordPage } from './features/auth/ResetPasswordPage';
import { SetupPage } from './features/auth/SetupPage';
import { SignInPage } from './features/auth/SignInPage';
import { AppLayout, NotFound } from './layout/AppLayout';
import { RequireAuth } from './layout/RequireAuth';
import { RequireServerAdmin } from './layout/RequireServerAdmin';

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
            // The token is in the link's fragment (`/invite#…`), which never reaches a server.
            { path: '/invite', element: <InvitationPage /> },
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
                                    { path: 'access', element: <AccessTab /> },
                                ],
                            },
                            { path: '/addresses', element: <AddressesPage /> },
                            { path: '/blocked', element: <BlockedPage /> },
                            { path: '/profile', element: <ProfilePage /> },
                            // Server admins only: anyone else gets Page not found, as the API answers them 404.
                            {
                                path: '/users',
                                element: (
                                    <RequireServerAdmin>
                                        <UsersPage />
                                    </RequireServerAdmin>
                                ),
                            },
                            {
                                path: '/users/:id',
                                element: (
                                    <RequireServerAdmin>
                                        <UserDetailPage />
                                    </RequireServerAdmin>
                                ),
                            },
                            { path: '*', element: <NotFound /> },
                        ],
                    },
                ],
            },
        ],
    },
];

export const router = createBrowserRouter(routes);
