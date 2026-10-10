import type { ReactNode } from 'react';
import { render } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { createMemoryRouter, RouterProvider, useLocation, type RouteObject } from 'react-router';

/** Prints the current path and query so a test can assert where a redirect landed. */
export function LocationProbe() {
    const location = useLocation();
    return <output data-testid="location">{location.pathname + location.search}</output>;
}

export function testQueryClient(): QueryClient {
    return new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
}

/** Renders `routes` at `path` with a catch-all probe route, inside a fresh query client. */
export function renderRoutes(routes: RouteObject[], path: string, client: QueryClient = testQueryClient()) {
    const router = createMemoryRouter([...routes, { path: '*', element: <LocationProbe /> }], {
        initialEntries: [path],
    });
    const wrap = (node: ReactNode) => <QueryClientProvider client={client}>{node}</QueryClientProvider>;
    const utils = render(wrap(<RouterProvider router={router} />));
    return { ...utils, router, client };
}
