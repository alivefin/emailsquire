import { useTranslation } from 'react-i18next';
import { Link, useParams } from 'react-router';
import { useAuditLog } from '../../api/auditLog';
import { Button } from '../../components/ui/Button';
import { EmptyState } from '../../components/ui/EmptyState';
import { errorText } from '../domain/shared';
import { ActivityList } from './ActivityList';

const LATEST = 20;

/** `/domains/:domain/activity`: the domain's latest entries, and the way into the full, filterable log. */
export function DomainActivityTab() {
    const { t } = useTranslation('activity');
    const { domain = '' } = useParams();
    const list = useAuditLog({ domain, per_page: LATEST });
    const rows = list.data?.data ?? [];

    return (
        <section aria-labelledby="domain-activity-title" className="flex flex-col rounded-[10px] border border-line bg-white">
            <h2 id="domain-activity-title" className="border-b border-line px-3.5 py-3 text-sm font-bold md:px-[18px] md:py-3.5 md:text-[15px]">
                {t('domain.title')}
            </h2>
            {list.isPending ? (
                <div aria-busy="true" aria-label={t('loading')} className="flex flex-col gap-2 px-3.5 py-3 md:px-[18px]">
                    {[0, 1, 2].map((i) => (
                        <div key={i} className="h-4 animate-pulse rounded bg-line-soft" />
                    ))}
                </div>
            ) : list.isError && !list.data ? (
                <div role="alert" className="flex items-center gap-3 px-4 py-6 text-[13px] text-danger">
                    <span className="min-w-0 flex-1">{errorText(list.error, t('loadFailed'))}</span>
                    <Button size="sm" onClick={() => void list.refetch()}>
                        {t('retry')}
                    </Button>
                </div>
            ) : rows.length === 0 ? (
                <EmptyState title={t('domain.empty')} />
            ) : (
                <ActivityList items={rows} />
            )}
            <div className="px-3.5 py-3 md:px-[18px]">
                <Link
                    to={`/activity?${new URLSearchParams({ domain }).toString()}`}
                    className="text-[12.5px] font-semibold text-teal-dark hover:underline"
                >
                    {t('domain.openLog')}
                </Link>
            </div>
        </section>
    );
}
