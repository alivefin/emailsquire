import { useState } from 'react';
import { useParams, useSearchParams } from 'react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import i18next from 'i18next';
import { useTranslation } from 'react-i18next';
import { apiFetch } from '../../api/client';
import { queryKeys } from '../../api/types';
import { Button } from '../../components/ui/Button';
import { Chip } from '../../components/ui/Chip';
import { cx } from '../../components/ui/cx';
import { DataTable, type DataTableColumn } from '../../components/ui/DataTable';
import { EmptyState } from '../../components/ui/EmptyState';
import { controlClass } from '../../components/ui/fieldStyles';
import { Menu } from '../../components/ui/Menu';
import { Pager } from '../../components/ui/Pager';
import { useToast } from '../../components/ui/Toast';
import { pageParam, useClampPage, useDebouncedSearchParam, useGoToPage } from '../../lib/listParams';
import { useIsPhone } from '../../lib/useIsPhone';
import { canWrite } from '../../lib/roles';
import { useDomainNames } from '../../layout/useNavData';
import { ForwardingDrawer } from './ForwardingDrawer';
import {
    displayAddress,
    errorText,
    forwardingPath,
    refreshAfterForwardingWrite,
    useSingleFlight,
    type ForwardingList,
    type ForwardingRow,
} from './shared';

type Kind = 'all' | 'local' | 'external';

const PER_PAGE = 10;
const panelClass = 'rounded-[10px] border border-line bg-white';

function kindParam(raw: string | null): Kind {
    return raw === 'local' || raw === 'external' ? raw : 'all';
}

function kindLabel(r: ForwardingRow): string {
    const t = (key: string, options: Record<string, unknown> = {}) => i18next.t(key, { ...options, ns: 'forwarding' });
    if (r.catch_all) return t('list.kindCatchAll');
    if (r.source_is_mailbox) return r.keep_copy ? t('list.kindKeepCopy') : t('list.kindForwardOnly');
    if (r.targets.length > 1) return t('list.kindPlaces', { count: r.targets.length });
    const kind = r.targets[0]?.kind;
    if (kind === 'external') return t('list.kindExternal');
    return kind === 'mailbox' ? t('list.kindMailbox') : t('list.kindServer');
}

function TargetChips({ r }: { r: ForwardingRow }) {
    const { t } = useTranslation('common');
    return (
        <ul className="flex flex-wrap gap-1.5">
            {r.targets.map((target) => (
                <li key={target.address} className="max-w-full">
                    <Chip kind={target.kind} title={t(`chip.kind.${target.kind}`)}>
                        {target.address}
                    </Chip>
                </li>
            ))}
        </ul>
    );
}

export function ForwardingTab() {
    const { t } = useTranslation('forwarding');
    const { domain = '' } = useParams();
    const phone = useIsPhone();
    const queryClient = useQueryClient();
    const toast = useToast();
    const once = useSingleFlight();
    const names = useDomainNames();

    const [params, setParams] = useSearchParams();
    const q = params.get('q') ?? '';
    const kind = kindParam(params.get('kind'));
    const page = pageParam(params);
    const [search, setSearch] = useDebouncedSearchParam();
    const goToPage = useGoToPage();

    const named = names.data?.find((d) => d.name === domain);
    const unicode = named?.unicode_name ?? domain;
    // A viewer gets no writes: no Add, no row menu, no drawer.
    const writable = canWrite(named?.role);
    const shown = (r: ForwardingRow) => (r.catch_all ? t('list.catchAll', { domain: unicode }) : displayAddress(r.local, unicode));

    // null while closed; `editing` is unset for a new group.
    const [drawer, setDrawer] = useState<{ editing?: ForwardingRow } | null>(null);

    const list = useQuery({
        queryKey: queryKeys.forwarding(domain, { q, kind, page }),
        queryFn: () =>
            apiFetch<ForwardingList>(forwardingPath(domain), {
                query: { q: q || undefined, kind: kind === 'all' ? undefined : kind, page, limit: PER_PAGE },
            }),
        placeholderData: (previous) => previous,
    });

    useClampPage(page, list.data?.meta.last_page, !list.isPlaceholderData);

    function setKind(value: Kind) {
        setParams((prev) => {
            const next = new URLSearchParams(prev);
            if (value === 'all') next.delete('kind');
            else next.set('kind', value);
            next.delete('page');
            return next;
        });
    }

    function clearFilters() {
        setSearch('');
        setParams({}, { replace: true });
    }

    function undo(r: ForwardingRow) {
        const json = {
            targets: r.targets.map((t) => t.address),
            keep_copy: r.keep_copy,
            // The mailbox was forward-only before: putting that back needs the same confirmation.
            ...(r.source_is_mailbox && !r.keep_copy ? { redirect_mailbox: true } : {}),
        };
        apiFetch<ForwardingRow>(forwardingPath(domain, r.local), { method: 'PUT', json }).then(
            async () => {
                toast.show({ kind: 'success', text: t('list.restored', { address: shown(r) }) });
                await refreshAfterForwardingWrite(queryClient);
            },
            (e: unknown) => toast.show({ kind: 'danger', text: errorText(e, t('list.restoreFailed', { address: shown(r) })) }),
        );
    }

    const remove = useMutation({
        mutationFn: (r: ForwardingRow) => apiFetch<undefined>(forwardingPath(domain, r.local), { method: 'DELETE' }),
        onSuccess: async (_v, r) => {
            toast.show({ kind: 'success', text: t('list.removed', { address: shown(r) }), action: { label: t('list.undo'), onClick: () => undo(r) } });
            await refreshAfterForwardingWrite(queryClient);
        },
        onError: (e) => toast.show({ kind: 'danger', text: errorText(e, t('list.removeFailed')) }),
    });

    const edit = (r: ForwardingRow) => setDrawer({ editing: r });
    const openNew = () => setDrawer({});

    // Catch-all rows come from outside the console; the API cannot change them, so they get no actions.
    const rowMenu = (r: ForwardingRow) =>
        r.catch_all || !writable ? null : (
            <Menu
                aria-label={t('list.actionsFor', { address: shown(r) })}
                items={[
                    { label: t('list.editDestinations'), onSelect: () => edit(r) },
                    { label: t('list.remove'), danger: true, disabled: remove.isPending, onSelect: () => once(() => remove.mutateAsync(r)) },
                ]}
            />
        );

    const sourceCell = (r: ForwardingRow) => (
        <div className="min-w-0">
            <div className="font-semibold wrap-anywhere text-ink">{shown(r)}</div>
            <div className="text-xs text-muted">{kindLabel(r)}</div>
        </div>
    );

    const columns: DataTableColumn<ForwardingRow>[] = [
        { key: 'source', header: t('list.columnAddress'), width: '34%', cell: sourceCell },
        { key: 'targets', header: t('list.columnDeliversTo'), cell: (r) => <TargetChips r={r} /> },
    ];
    if (writable) {
        columns.push({ key: 'actions', header: <span className="sr-only">{t('list.actions')}</span>, width: '60px', align: 'right', cell: rowMenu });
    }

    const card = (r: ForwardingRow) => (
        <div className={cx(panelClass, 'flex flex-col gap-2 px-3.5 py-3')}>
            <div className="flex items-start gap-2">
                <div className="min-w-0 flex-1">
                    <div className="text-sm font-semibold wrap-anywhere text-ink">{shown(r)}</div>
                    <div className="text-xs text-muted">{kindLabel(r)}</div>
                </div>
                {rowMenu(r)}
            </div>
            <TargetChips r={r} />
        </div>
    );

    const filtered = q !== '' || kind !== 'all';
    const addButton = writable ? (
        <Button variant="primary" onClick={openNew}>
            {t('list.add')}
        </Button>
    ) : null;
    const empty = !filtered ? (
        <EmptyState title={t('list.emptyTitle', { domain: unicode })} action={addButton}>
            {t('list.emptyHint')}
        </EmptyState>
    ) : (
        <EmptyState
            title={t('list.noMatch')}
            action={
                <div className="flex justify-center gap-2">
                    <Button onClick={clearFilters}>{t('list.clear')}</Button>
                    {addButton}
                </div>
            }
        >
            {t('list.tryFewer')}
        </EmptyState>
    );

    const failed = list.isError && !list.data;

    return (
        <div className={cx('flex flex-col', !phone && panelClass)}>
            <div className="flex items-center gap-2 md:gap-2.5 md:border-b md:border-line md:px-[18px] md:py-3.5">
                <input
                    type="search"
                    aria-label={t('list.search')}
                    placeholder={phone ? t('list.searchPhone') : t('list.searchFull')}
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    className={cx(controlClass, 'border-line-strong md:h-9 md:w-[260px] md:text-[13.5px] max-md:flex-1')}
                />
                <select
                    aria-label={t('list.kind')}
                    value={kind}
                    onChange={(e) => setKind(kindParam(e.target.value))}
                    className={cx(controlClass, 'w-auto border-line-strong font-semibold text-body md:h-9 md:text-[13px] max-md:max-w-[110px]')}
                >
                    <option value="all">{phone ? t('list.kindAllPhone') : t('list.kindAll')}</option>
                    <option value="local">{phone ? t('list.kindLocalPhone') : t('list.kindLocal')}</option>
                    <option value="external">{phone ? t('list.kindExternalPhone') : t('list.kindExternalOption')}</option>
                </select>
                <div className="flex-1 max-md:hidden" />
                {writable &&
                    (phone ? (
                        <Button variant="primary" aria-label={t('list.add')} className="w-12 flex-none text-xl" onClick={openNew}>
                            +
                        </Button>
                    ) : (
                        <Button variant="primary" onClick={openNew}>
                            {t('list.addPlus')}
                        </Button>
                    ))}
            </div>
            {failed ? (
                <div role="alert" className="flex items-center gap-3 px-4 py-6 text-[13px] text-danger">
                    <span className="min-w-0 flex-1">{errorText(list.error, t('list.loadFailed'))}</span>
                    <Button size="sm" onClick={() => void list.refetch()}>
                        {t('list.retry')}
                    </Button>
                </div>
            ) : (
                <div className="max-md:mt-3">
                    <DataTable
                        rows={list.data?.data ?? []}
                        rowKey={(r) => r.source}
                        columns={columns}
                        card={card}
                        loading={list.isPending}
                        empty={empty}
                    />
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
            <ForwardingDrawer open={drawer !== null && writable} onClose={() => setDrawer(null)} domain={domain} editing={drawer?.editing} />
        </div>
    );
}
