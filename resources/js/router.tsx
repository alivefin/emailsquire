import { createBrowserRouter, Navigate, Outlet, type DataRouter, type RouteObject } from 'react-router';
import { StepUpDialog } from './components/StepUpDialog';
import { AccessTab } from './features/access/AccessTab';
import { ActivityPage } from './features/activity/ActivityPage';
import { DomainActivityTab } from './features/activity/DomainActivityTab';
import { AddressesPage } from './features/addresses/AddressesPage';
import { BlockedPage } from './features/blocked/BlockedPage';
import { DashboardPage } from './features/dashboard/DashboardPage';
import { DomainPage } from './features/domain/DomainPage';
import { ForwardingTab } from './features/domain/ForwardingTab';
import { MailboxesTab } from './features/domain/MailboxesTab';
import { DomainsPage } from './features/domains/DomainsPage';
import { DnsTab } from './features/dns/DnsTab';
import { ProfilePage } from './features/profile/ProfilePage';
import { UserDetailPage } from './features/users/UserDetailPage';
import { UsersPage } from './features/users/UsersPage';
import { ForgotPasswordPage } from './features/auth/ForgotPasswordPage';
import { InvitationPage } from './features/auth/InvitationPage';
import { ResetPasswordPage } from './features/auth/ResetPasswordPage';
import { SetupPage } from './features/auth/SetupPage';
import { SignInPage } from './features/auth/SignInPage';
import { PortalHelp } from './features/portal/PortalHelp';
import { PortalAliases } from './features/portal/PortalAliases';
import { PortalForwarding } from './features/portal/PortalForwarding';
import { PortalHome } from './features/portal/PortalHome';
import { PortalLayout, PortalNotFound } from './features/portal/PortalLayout';
import { PortalPassword } from './features/portal/PortalPassword';
import { PortalSettings } from './features/portal/PortalSettings';
import { AppLayout, NotFound } from './layout/AppLayout';
import { RequireAuth } from './layout/RequireAuth';
import { RequireMailbox } from './layout/RequireMailbox';
import { RequireServerAdmin } from './layout/RequireServerAdmin';
import { ModuleBoundary, ModuleErrorPanel } from './modules/ModuleBoundary';
import { refuseModule, registeredModules, routerAccepts, type ModuleDefinition } from './modules/registry';

// The placeholder pages are replaced by the real screens in later tasks.
// The root element hosts the step-up dialog: it sits inside the router because it shares the modal
// machinery (useModal reads the location), and above every page so any request can ask for it.
// Module routes join the console layout's children before its catch-all.
export function appRoutes(modules: RouteObject[] = []): RouteObject[] {
    return [
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
                // The mailbox portal: a mailbox owner's session only. Its guard sends a console session to /, and
                // the console's guard sends a portal session here.
                {
                    path: '/my',
                    element: (
                        <RequireMailbox>
                            <PortalLayout />
                        </RequireMailbox>
                    ),
                    children: [
                        { index: true, element: <PortalHome /> },
                        { path: 'password', element: <PortalPassword /> },
                        { path: 'forwarding', element: <PortalForwarding /> },
                        { path: 'aliases', element: <PortalAliases /> },
                        { path: 'settings', element: <PortalSettings /> },
                        { path: 'help', element: <PortalHelp /> },
                        { path: '*', element: <PortalNotFound /> },
                    ],
                },
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
                                        // Every role reads it; Check now and the DKIM actions follow the role.
                                        { path: 'dns', element: <DnsTab /> },
                                        { path: 'activity', element: <DomainActivityTab /> },
                                    ],
                                },
                                { path: '/addresses', element: <AddressesPage /> },
                                { path: '/blocked', element: <BlockedPage /> },
                                // Everyone: the server returns only the rows the user may read.
                                { path: '/activity', element: <ActivityPage /> },
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
                                ...modules,
                                { path: '*', element: <NotFound /> },
                            ],
                        },
                    ],
                },
            ],
        },
    ];
}

/** The console's own routes, without modules. */
export const routes: RouteObject[] = appRoutes();

/**
 * Each module's top-level routes, with its screen inside an error boundary. A loader or action error
 * shows the same panel inside the layout, unless the module brings its own error element.
 */
export function moduleRoutes(modules: ModuleDefinition[] = registeredModules()): RouteObject[] {
    return modules.flatMap((m) =>
        m.routes.map(({ Component, element, ...route }) => ({
            ...route,
            // React Router warns when both are set, so a module's own ErrorBoundary goes alone.
            ...(route.errorElement === undefined && route.ErrorBoundary === undefined ? { errorElement: <ModuleErrorPanel /> } : {}),
            element: <ModuleBoundary>{element ?? (Component ? <Component /> : <Outlet />)}</ModuleBoundary>,
        })),
    );
}

/**
 * The modules whose routes React Router accepts beside the core's and the earlier modules'. Each module's
 * routes were checked alone when it registered; together they can still repeat a route id.
 */
function compatibleModules(): ModuleDefinition[] {
    const all = registeredModules();
    if (routerAccepts(appRoutes(moduleRoutes(all)))) return all;
    const accepted: ModuleDefinition[] = [];
    for (const m of all) {
        if (routerAccepts(appRoutes(moduleRoutes([...accepted, m])))) accepted.push(m);
        else refuseModule(m.name, 'route_clash');
    }
    return accepted;
}

/** Built once module loading has settled, so the registered modules' routes are in it. */
export function createRouter(): DataRouter {
    return createBrowserRouter(appRoutes(moduleRoutes(compatibleModules())));
}
