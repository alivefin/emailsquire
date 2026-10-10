import { createBrowserRouter, type RouteObject } from 'react-router';

export const routes: RouteObject[] = [{ path: '/', element: <h1>EmailSquire</h1> }];

export const router = createBrowserRouter(routes);
