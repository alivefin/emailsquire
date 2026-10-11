import { useTranslation } from 'react-i18next';
import { ACTION_GROUPS, type AuditActor } from '../../api/auditLog';
import { Button } from '../../components/ui/Button';
import { cx } from '../../components/ui/cx';
import { controlClass } from '../../components/ui/fieldStyles';
import type { DomainName } from '../../layout/useNavData';
import { actorLabel } from '../dashboard/activityText';
import { hasFilters, type ActivityFilterValues, type FilterKey } from './filters';

const selectClass = 'w-auto border-line-strong font-semibold text-body md:h-9 md:max-w-[200px] md:text-[13px]';
const dateClass = 'w-auto border-line-strong text-body md:h-9 md:w-[150px] md:text-[13px]';

export interface ActivityFiltersProps {
    values: ActivityFilterValues;
    onChange: (key: FilterKey, value: string) => void;
    onClear: () => void;
    domains: DomainName[];
    actors: AuditActor[];
    /** The requester's id: their own entry in the person list says "(you)". */
    meId: number | undefined;
    /** Server admins also get the account group; for anyone else it is always empty. */
    admin: boolean;
}

/** The person filter's label for one actor. */
function useActorOption() {
    const { t } = useTranslation('activity');
    return (a: AuditActor, meId: number | undefined): string => {
        if (a.kind === 'user') return a.key === String(meId) ? t('actor.you', { name: a.name }) : a.name;
        if (a.kind === 'deleted_user') return t('actor.deletedUser', { id: a.key });
        return actorLabel({ actor_kind: a.kind, actor_name: a.name });
    };
}

export function ActivityFilters({ values, onChange, onClear, domains, actors, meId, admin }: ActivityFiltersProps) {
    const { t } = useTranslation('activity');
    const actorOption = useActorOption();
    // An admin-only group stays listed while the URL selects it, so the select never shows another value.
    const groups = ACTION_GROUPS.filter((g) => g !== 'account' || admin || values.group === 'account');
    // Likewise a domain or person from the URL that the lists don't (yet) contain.
    const domainListed = values.domain === '' || domains.some((d) => d.name === values.domain);
    const actorListed = values.actor === '' || actors.some((a) => a.key === values.actor);

    return (
        <div role="group" aria-label={t('filters.label')} className="grid grid-cols-2 gap-2 md:flex md:flex-wrap md:items-center md:gap-2.5">
            <select
                aria-label={t('filters.domain')}
                value={values.domain}
                onChange={(e) => onChange('domain', e.target.value)}
                className={cx(controlClass, selectClass)}
            >
                <option value="">{t('filters.allDomains')}</option>
                {domains.map((d) => (
                    <option key={d.name} value={d.name}>
                        {d.unicode_name}
                    </option>
                ))}
                {!domainListed && <option value={values.domain}>{values.domain}</option>}
            </select>
            <select
                aria-label={t('filters.person')}
                value={values.actor}
                onChange={(e) => onChange('actor', e.target.value)}
                className={cx(controlClass, selectClass)}
            >
                <option value="">{t('filters.everyone')}</option>
                {actors.map((a) => (
                    <option key={a.key} value={a.key}>
                        {actorOption(a, meId)}
                    </option>
                ))}
                {!actorListed && <option value={values.actor}>{values.actor}</option>}
            </select>
            <select
                aria-label={t('filters.group')}
                value={values.group}
                onChange={(e) => onChange('group', e.target.value)}
                className={cx(controlClass, selectClass, 'col-span-2 md:col-span-1')}
            >
                <option value="">{t('filters.allActions')}</option>
                {groups.map((g) => (
                    <option key={g} value={g}>
                        {t(`groups.${g}`)}
                    </option>
                ))}
            </select>
            <DateField label={t('filters.from')} value={values.from} max={values.to || undefined} onChange={(v) => onChange('from', v)} />
            <DateField label={t('filters.to')} value={values.to} min={values.from || undefined} onChange={(v) => onChange('to', v)} />
            {hasFilters(values) && (
                <Button size="sm" className="col-span-2 max-md:h-12 md:ml-0.5" onClick={onClear}>
                    {t('filters.clear')}
                </Button>
            )}
        </div>
    );
}

function DateField({ label, value, min, max, onChange }: { label: string; value: string; min?: string; max?: string; onChange: (value: string) => void }) {
    return (
        <label className="flex min-w-0 flex-col gap-1 text-[12px] font-semibold text-muted md:flex-row md:items-center md:gap-1.5 md:text-[12.5px]">
            {label}
            <input type="date" value={value} min={min} max={max} onChange={(e) => onChange(e.target.value)} className={cx(controlClass, dateClass)} />
        </label>
    );
}
