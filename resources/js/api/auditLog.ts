import { useQuery } from '@tanstack/react-query';
import auditActions from './auditActions.json';
import { apiFetch } from './client';
import type { operations } from './schema';
import { queryKeys, type Json } from './types';

export type AuditList = Json<'/audit-log', 'get'>;
export type AuditRow = AuditList['data'][number];
export type AuditActor = Json<'/audit-log/actors', 'get'>['data'][number];

type AuditParams = NonNullable<operations['auditLog.index']['parameters']['query']>;
export type ActionGroup = NonNullable<AuditParams['action_group']>;

/**
 * The action groups in the backend's order, from `AuditActions::GROUPS` (auditActions.json, kept equal to the
 * PHP constant by AuditActionsExportTest). The annotation fails the type check if the export names a group
 * the API doesn't accept.
 */
export const ACTION_GROUPS: ActionGroup[] = Object.keys(auditActions) as (keyof typeof auditActions)[];

export function isActionGroup(value: unknown): value is ActionGroup {
    return typeof value === 'string' && (ACTION_GROUPS as string[]).includes(value);
}

/** The `GET /audit-log` query: only the filters that are set. */
export interface AuditQuery {
    domain?: string;
    actor?: string;
    action_group?: ActionGroup;
    from?: string;
    to?: string;
    before_id?: number;
    page?: number;
    per_page?: number;
}

export interface AuditLogOptions {
    /** Keep the previous rows on screen while the next page or filter loads (the activity log's paging). */
    keepPrevious?: boolean;
    /** False while the query isn't known yet (a Unicode domain waiting for the names list). */
    enabled?: boolean;
}

export function useAuditLog(query: AuditQuery, { keepPrevious = false, enabled = true }: AuditLogOptions = {}) {
    return useQuery({
        enabled,
        queryKey: queryKeys.auditLog({ ...query }),
        queryFn: () => apiFetch<AuditList>('/audit-log', { query: { ...query } }),
        placeholderData: keepPrevious ? (previous: AuditList | undefined) => previous : undefined,
    });
}

/** The people (and the command line and system) in the rows the requester may read, for the person filter. */
export function useAuditActors() {
    return useQuery({
        queryKey: queryKeys.auditActors,
        queryFn: () => apiFetch<Json<'/audit-log/actors', 'get'>>('/audit-log/actors'),
        staleTime: 60_000,
        select: (r) => r.data,
    });
}
