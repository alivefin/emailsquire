import { isActionGroup, type ActionGroup, type AuditQuery } from '../../api/auditLog';
import { dayAfterStart, dayStart, parseDay } from '../../lib/dateRange';

/** The activity log's filters as the URL keeps them: '' for a filter that is not set. */
export interface ActivityFilterValues {
    /** As the URL has it, lowercased; `resolveDomain` turns a Unicode name into the ASCII one. */
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
/** A domain name in ASCII or Unicode (lowercased): two or more labels of letters, digits and inner hyphens. */
const LABEL = '[\\p{L}\\p{N}](?:[\\p{L}\\p{N}-]{0,61}[\\p{L}\\p{N}])?';
const DOMAIN = new RegExp(`^(?=.{1,253}$)(?:${LABEL}\\.)+${LABEL}$`, 'u');
const ASCII = /^[\x20-\x7e]*$/;

/** The filters in `params`, each malformed value read as not set. */
export function readFilters(params: URLSearchParams): ActivityFilterValues {
    const actor = params.get('actor') ?? '';
    const group = params.get('group');
    const domain = (params.get('domain') ?? '').trim().toLowerCase();
    return {
        domain: DOMAIN.test(domain) ? domain : '',
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

/**
 * The `domain` filter as the API and the domain select want it: a Unicode name from the URL (a link or a
 * typed address) becomes the ASCII name of the user's domain with that name. `ready` is false while such a
 * name waits for the names list. A Unicode name that isn't one of the user's domains is sent as it is: the
 * API reads it, and an unknown domain gives an empty page.
 */
export function resolveDomain(domain: string, names: { name: string; unicode_name: string }[] | undefined): { domain: string; ready: boolean } {
    if (domain === '') return { domain, ready: true };
    const hit = names?.find((d) => d.name === domain || d.unicode_name.toLowerCase() === domain);
    if (hit) return { domain: hit.name, ready: true };
    return { domain, ready: names !== undefined || ASCII.test(domain) };
}
