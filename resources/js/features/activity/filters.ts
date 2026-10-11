import { isActionGroup, type ActionGroup, type AuditQuery } from '../../api/auditLog';
import { dayAfterStart, dayStart, parseDay } from '../../lib/dateRange';

/** The activity log's filters as the URL keeps them: '' for a filter that is not set. */
export interface ActivityFilterValues {
    domain: string;
    /** A user id, `cli` or `system`. */
    actor: string;
    group: ActionGroup | '';
    /** `YYYY-MM-DD`, the first day shown. */
    from: string;
    /** `YYYY-MM-DD`, the last day shown. */
    to: string;
}

export type FilterKey = keyof ActivityFilterValues;

/** The URL parameters of the filters; `page` and `before` (the paging anchor) are the others. */
export const FILTER_KEYS: FilterKey[] = ['domain', 'actor', 'group', 'from', 'to'];

const ACTOR = /^(cli|system|[1-9][0-9]{0,18})$/;

/** The filters in `params`, each malformed value read as not set. */
export function readFilters(params: URLSearchParams): ActivityFilterValues {
    const actor = params.get('actor') ?? '';
    const group = params.get('group');
    return {
        domain: (params.get('domain') ?? '').trim(),
        actor: ACTOR.test(actor) ? actor : '',
        group: isActionGroup(group) ? group : '',
        from: parseDay(params.get('from')),
        to: parseDay(params.get('to')),
    };
}

/** The paging anchor (`before`): the newest row's id when the first page was shown. */
export function readAnchor(params: URLSearchParams): number | undefined {
    const raw = params.get('before') ?? '';
    return /^[1-9][0-9]{0,15}$/.test(raw) ? Number(raw) : undefined;
}

export function hasFilters(f: ActivityFilterValues): boolean {
    return FILTER_KEYS.some((k) => f[k] !== '');
}

/** The API query for the filters: the days become instants in the browser's time zone. */
export function filterQuery(f: ActivityFilterValues): AuditQuery {
    const query: AuditQuery = {};
    if (f.domain) query.domain = f.domain;
    if (f.actor) query.actor = f.actor;
    if (f.group) query.action_group = f.group;
    if (f.from) query.from = dayStart(f.from);
    if (f.to) query.to = dayAfterStart(f.to);
    return query;
}
