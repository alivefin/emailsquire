import { createBrowserRouter, type RouteObject } from 'react-router';
import { SetupPage } from './features/auth/SetupPage';
import { SignInPage } from './features/auth/SignInPage';
import { RequireAuth } from './layout/RequireAuth';

export const routes: RouteObject[] = [
    { path: '/signin', element: <SignInPage /> },
    { path: '/setup/:token', element: <SetupPage /> },
    {
        element: <RequireAuth />,
        children: [{ path: '/', element: <h1>EmailSquire</h1> }],
    },
];

export const router = createBrowserRouter(routes);
