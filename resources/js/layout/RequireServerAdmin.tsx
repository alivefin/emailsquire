import type { ReactNode } from 'react';
import { useMe } from '../features/auth/useMe';
import { isServerAdmin } from '../lib/roles';
import { NotFound } from './AppLayout';

/**
 * Server-admin screens (Users). To anyone else they don't exist, as the API's 404 says: Page not found.
 * `RequireAuth` has loaded `me` by now; nothing renders while it is still unknown.
 */
export function RequireServerAdmin({ children }: { children: ReactNode }) {
    const me = useMe();
    if (me.data === undefined) return null;
    return isServerAdmin(me.data) ? children : <NotFound />;
}
