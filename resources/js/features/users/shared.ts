import type { QueryClient } from '@tanstack/react-query';
import i18next from 'i18next';
import { queryKeys, type Json } from '../../api/types';

export type UserList = Json<'/users', 'get'>;
export type UserRow = UserList['data'][number];
export type UserDetail = Json<'/users/{user}', 'get'>;
export type UserGrant = UserDetail['grants'][number];
export type UserInvitation = UserDetail['invitations'][number];
export type InvitationList = Json<'/users/invitations', 'get'>;
export type ListedInvitation = InvitationList['data'][number];

/** A path under `/users`, each segment encoded: the API's (`/users/7/grants`) and the console's (`/users/7`). */
export function usersPath(...segments: (string | number)[]): string {
    return ['', 'users', ...segments.map(String)].map(encodeURIComponent).join('/');
}

/** A user's name, or their email when the name is empty. */
export function userName(u: { name: string; email: string }): string {
    return u.name.trim() === '' ? u.email : u.name;
}

/** "Server admin", "Manager of 2 · Viewer of 1" or "No domains". */
export function roleSummary(u: Pick<UserRow, 'is_server_admin' | 'manager_of' | 'viewer_of'>): string {
    const t = (key: string, options?: Record<string, unknown>) => i18next.t(key, { ns: 'users', ...options });
    if (u.is_server_admin) return t('role.serverAdmin');
    const parts: string[] = [];
    if (u.manager_of > 0) parts.push(t('role.managerOf', { count: u.manager_of }));
    if (u.viewer_of > 0) parts.push(t('role.viewerOf', { count: u.viewer_of }));
    return parts.length === 0 ? t('role.noDomains') : parts.join(' · ');
}

/**
 * Refetches what a change to a user can touch: the users list and pages (and the sidebar count), and the
 * domains, whose People column and Access tabs list the people with a grant. Your own change refreshes `me`.
 */
export async function refreshAfterUserWrite(client: QueryClient, self: boolean): Promise<void> {
    await Promise.all([
        client.invalidateQueries({ queryKey: ['users'] }),
        client.invalidateQueries({ queryKey: ['domains'] }),
        ...(self ? [client.invalidateQueries({ queryKey: queryKeys.me })] : []),
    ]);
}
