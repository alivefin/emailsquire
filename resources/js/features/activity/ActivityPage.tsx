import { useTranslation } from 'react-i18next';
import { useSearchParams } from 'react-router';
import { useAuditActors, useAuditLog, type AuditRow } from '../../api/auditLog';
import { Button } from '../../components/ui/Button';
import { cx } from '../../components/ui/cx';
import { DataTable, type DataTableColumn } from '../../components/ui/DataTable';
import { EmptyState } from '../../components/ui/EmptyState';
import { Pager } from '../../components/ui/Pager';
import { pageParam, useClampPage } from '../../lib/listParams';
import { isServerAdmin } from '../../lib/roles';
import { useIsPhone } from '../../lib/useIsPhone';
import { useDomainNames } from '../../layout/useNavData';
import { useMe } from '../auth/useMe';
import { activityText, actorLabel } from '../dashboard/activityText';
import { errorText } from '../domain/shared';
import { ActivityTime } from './ActivityList';
import { ActivityFilters } from './ActivityFilters';
import { filterQuery, FILTER_KEYS, hasFilters, readAnchor, readFilters, resolveDomain, type FilterKey } from './filters';

const PER_PAGE = 10;
const panelClass = 'rounded-[10px] border border-line bg-white';

/**
 * `/activity`: the audit rows the user may read, newest first. The filters, the page and the paging
 * anchor live in the URL, so a reload or the back button shows the same list.
 */
export function ActivityPage() {
    const { t } = useTranslation('activity');
    const phone = useIsPhone();
    const me = useMe().data;
    const names = useDomainNames();
    const actors = useAuditActors();
    const [params, setParams] = useSearchParams();
    const fromUrl = readFilters(params);
    const resolved = resolveDomain(fromUrl.domain, names.isPending ? undefined : (names.data ?? []));
    const filters = { ...fromUrl, domain: resolved.domain };
    const page = pageParam(params);
    // Page 1 always shows the newest rows: a `before` left in the URL only counts from page 2 on.
    const anchor = page > 1 ? readAnchor(params) : undefined;

    const list = useAuditLog(
        { ...filterQuery(filters), ...(anchor !== undefined && { before_id: anchor }), page, per_page: PER_PAGE },
        { keepPrevious: true, enabled: resolved.ready },
    );
    useClampPage(page, list.data?.meta.last_page, !list.isPlaceholderData);

    /** A new filter value starts again at page 1, without the anchor, so new rows show up. */
    function setFilter(key: FilterKey, value: string) {
        setParams((prev) => {
            const next = new URLSearchParams(prev);
            if (value === '') next.delete(key);
            else next.set(key, value);
            next.delete('page');
            next.delete('before');
            return next;
        });
    }

    function clearFilters() {
        setParams((prev) => {
            const next = new URLSearchParams(prev);
            for (const key of [...FILTER_KEYS, 'page', 'before']) next.delete(key);
            return next;
        });
    }

    /**
     * Page 1 always shows the newest rows. Leaving it pins the later pages to its newest row (`before`),
     * so rows written meanwhile don't push entries from one page onto the next; moving between later
     * pages keeps the pin.
     *
     * Without an anchor there is nothing to pin to: landing on `?page=2` without `before` (a hand-edited
     * or old link), or leaving page 1 before its rows have loaded, pages unanchored, as the other lists
     * do, until the user is back on page 1. That page is correct, only not protected against new rows.
     */
    function goToPage(p: number) {
        const newest = page === 1 && !list.isPlaceholderData ? list.data?.data[0]?.id : undefined;
        setParams((prev) => {
            const next = new URLSearchParams(prev);
            if (p <= 1) {
                next.delete('page');
                next.delete('before');
                return next;
            }
            next.set('page', String(p));
            if (page === 1) {
                if (newest === undefined) next.delete('before');
                else next.set('before', String(newest));
            }
            return next;
        });
    }

    const unicodeOf = (name: string) => names.data?.find((d) => d.name === name)?.unicode_name ?? name;
    const domainCell = (r: AuditRow) => (r.domain_name ? unicodeOf(r.domain_name) : '—');

    // Below 1024 px (820 px beside the sidebar) the person moves into the sentence and the object column
    // goes, since the sentence names the object too: time, who did what, domain.
    const columns: DataTableColumn<AuditRow>[] = [
        { key: 'time', header: t('columns.time'), width: '112px', cell: (r) => <ActivityTime at={r.occurred_at} className="whitespace-nowrap text-muted" /> },
        { key: 'person', header: t('columns.person'), className: 'max-lg:hidden', cell: (r) => <span className="font-semibold text-ink wrap-anywhere">{actorLabel(r)}</span> },
        {
            key: 'action',
            header: t('columns.action'),
            cell: (r) => (
                <span className="wrap-anywhere">
                    <b className="font-semibold text-ink lg:hidden">{actorLabel(r)} </b>
                    {activityText(r)}
                </span>
            ),
        },
        { key: 'object', header: t('columns.object'), className: 'max-lg:hidden', cell: (r) => <span className="text-[13px] wrap-anywhere">{r.object_key}</span> },
        { key: 'domain', header: t('columns.domain'), cell: (r) => <span className="text-[13px] wrap-anywhere">{domainCell(r)}</span> },
    ];

    const card = (r: AuditRow) => (
        <article className={cx(panelClass, 'flex flex-col gap-1 px-3.5 py-3 text-[13px] text-body')}>
            <div className="flex items-center gap-2 text-[11.5px] text-muted">
                <ActivityTime at={r.occurred_at} className="flex-none" />
                {r.domain_name && <span className="ml-auto min-w-0 truncate">{unicodeOf(r.domain_name)}</span>}
            </div>
            <p className="wrap-anywhere">
                <b className="font-semibold text-ink">{actorLabel(r)}</b> {activityText(r)}
            </p>
        </article>
    );

    const filtered = hasFilters(filters);
    const empty = filtered ? (
        <EmptyState title={t('empty.noMatches')} action={<Button onClick={clearFilters}>{t('filters.clear')}</Button>}>
            {t('empty.noMatchesHint')}
        </EmptyState>
    ) : (
        <EmptyState title={t('empty.none')}>{t('empty.noneHint')}</EmptyState>
    );

    const failed = list.isError && !list.data;

    return (
        <div className={cx('flex flex-col', !phone && panelClass)}>
            <div className="flex flex-col gap-2 md:flex-row md:items-center md:gap-3 md:border-b md:border-line md:px-[18px] md:py-3.5">
                <ActivityFilters
                    values={filters}
                    onChange={setFilter}
                    onClear={clearFilters}
                    domains={names.data ?? []}
                    actors={actors.data ?? []}
                    meId={me?.id}
                    admin={isServerAdmin(me)}
                />
                {list.data && (
                    <span className="flex-none text-xs text-muted md:ml-auto md:text-[12.5px]">{t('count', { count: list.data.meta.total })}</span>
                )}
            </div>
            {failed ? (
                <div role="alert" className="flex items-center gap-3 px-4 py-6 text-[13px] text-danger">
                    <span className="min-w-0 flex-1">{errorText(list.error, t('loadFailed'))}</span>
                    <Button size="sm" onClick={() => void list.refetch()}>
                        {t('retry')}
                    </Button>
                </div>
            ) : (
                <div className="max-md:mt-3">
                    <DataTable rows={list.data?.data ?? []} rowKey={(r) => String(r.id)} columns={columns} card={card} loading={list.isPending} empty={empty} />
                </div>
            )}
            {list.data && list.data.meta.total > 0 && (
                <Pager
                    className="py-3 max-md:px-0.5 md:px-[18px]"
                    page={list.data.meta.page}
                    perPage={list.data.meta.per_page}
                    total={list.data.meta.total}
                    onPage={goToPage}
                />
            )}
        </div>
    );
}
