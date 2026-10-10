import { useEffect, useRef, useState } from 'react';
import { useParams, useSearchParams } from 'react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import i18next from 'i18next';
import { useTranslation } from 'react-i18next';
import { apiFetch } from '../../api/client';
import { queryKeys, type Json } from '../../api/types';
import { Button } from '../../components/ui/Button';
import { Chip } from '../../components/ui/Chip';
import { cx } from '../../components/ui/cx';
import { DataTable, type DataTableColumn } from '../../components/ui/DataTable';
import { EmptyState } from '../../components/ui/EmptyState';
import { controlClass } from '../../components/ui/fieldStyles';
import { Menu, type MenuItem } from '../../components/ui/Menu';
import { Pager } from '../../components/ui/Pager';
import { StorageBar } from '../../components/ui/StorageBar';
import { useToast } from '../../components/ui/Toast';
import { formatBytes } from '../../lib/format';
import { pageParam, useClampPage, useDebouncedSearchParam, useGoToPage } from '../../lib/listParams';
import { useIsPhone } from '../../lib/useIsPhone';
import { useDomainNames } from '../../layout/useNavData';
import { BulkLimitDialog } from './BulkLimitDialog';
import { DeleteMailboxDialog } from './DeleteMailboxDialog';
import {
    displayAddress,
    errorText,
    mailboxCount,
    mailboxesPath,
    refreshAfterMailboxWrite,
    useDomainActions,
    useSingleFlight,
    type Mailbox,
    type MailboxList,
} from './shared';

type BulkResult = Json<'/domains/{domain}/mailboxes/bulk', 'post'>;
type Status = 'all' | 'active' | 'disabled';
type Sort = 'address' | '-address';

const PER_PAGE = 10;
const panelClass = 'rounded-[10px] border border-line bg-white';

function statusParam(raw: string | null): Status {
    return raw === 'active' || raw === 'disabled' ? raw : 'all';
}

function storageCaption(m: Mailbox): string {
    const t = (key: string, options: Record<string, unknown>) => i18next.t(key, { ...options, ns: 'mailboxes' });
    const limit = formatBytes(m.quota_bytes);
    const text =
        m.used_bytes === null ? t('caption.notChecked', { limit }) : t('caption.usedOf', { used: formatBytes(m.used_bytes), limit });
    return m.quota_is_default ? t('caption.withDefault', { text }) : text;
}

function StatusChip({ m }: { m: Mailbox }) {
    const { t } = useTranslation('mailboxes');
    return m.status === 'active' ? (
        <Chip kind="mailbox">{t('status.active')}</Chip>
    ) : (
        <Chip kind="server" title={t('status.disabledHint')}>
            {t('status.disabled')}
        </Chip>
    );
}

export function MailboxesTab() {
    const { t } = useTranslation('mailboxes');
    const { domain = '' } = useParams();
    const phone = useIsPhone();
    const queryClient = useQueryClient();
    const toast = useToast();
    const once = useSingleFlight();
    const { openNewMailbox, openEditMailbox } = useDomainActions();
    const names = useDomainNames();

    const [params, setParams] = useSearchParams();
    const q = params.get('q') ?? '';
    const status = statusParam(params.get('status'));
    const sort: Sort = params.get('sort') === '-address' ? '-address' : 'address';
    const page = pageParam(params);
    const [search, setSearch] = useDebouncedSearchParam();
    const goToPage = useGoToPage();

    // `?open=<local>` comes from the All addresses screen: open that mailbox once, then drop the parameter.
    const openLocal = params.get('open');
    useEffect(() => {
        if (openLocal === null) return;
        if (openLocal !== '') openEditMailbox(openLocal);
        setParams(
            (prev) => {
                const next = new URLSearchParams(prev);
                next.delete('open');
                return next;
            },
            { replace: true },
        );
    }, [openLocal, openEditMailbox, setParams]);

    const named = names.data?.find((d) => d.name === domain);
    const unicode = named?.unicode_name ?? domain;
    const shown = (m: Mailbox) => displayAddress(m.local, unicode);

    const list = useQuery({
        queryKey: queryKeys.mailboxes(domain, { q, status, sort, page }),
        queryFn: () =>
            apiFetch<MailboxList>(mailboxesPath(domain), {
                query: {
                    q: q || undefined,
                    status: status === 'all' ? undefined : status,
                    sort: sort === '-address' ? 'desc' : undefined,
                    page,
                    limit: PER_PAGE,
                },
            }),
        placeholderData: (previous) => previous,
    });

    useClampPage(page, list.data?.meta.last_page, !list.isPlaceholderData);

    // The selection belongs to the rows on screen: a new page, search, filter or sort starts empty.
    const viewKey = JSON.stringify([domain, q, status, sort, page]);
    const [selection, setSelection] = useState<{ view: string; locals: Set<string> }>({ view: viewKey, locals: new Set() });
    const selected = selection.view === viewKey ? selection.locals : new Set<string>();
    const setSelected = (locals: Set<string>) => setSelection({ view: viewKey, locals });

    const rows = list.data?.data ?? [];
    const selectedLocals = rows.filter((m) => selected.has(m.local)).map((m) => m.local);

    const [limitOpen, setLimitOpen] = useState(false);
    const [deleting, setDeleting] = useState<Mailbox | null>(null);
    // Where focus goes after a delete takes away the row whose menu opened the dialog.
    const searchRef = useRef<HTMLInputElement>(null);

    function setParam(key: string, value: string | null) {
        setParams((prev) => {
            const next = new URLSearchParams(prev);
            if (value === null) next.delete(key);
            else next.set(key, value);
            next.delete('page');
            return next;
        });
    }

    function clearFilters() {
        setSearch('');
        setParams({}, { replace: true });
    }

    const bulk = useMutation({
        mutationFn: (vars: { action: 'disable' | 'enable'; locals: string[] }) =>
            apiFetch<BulkResult>(`${mailboxesPath(domain)}/bulk`, {
                method: 'POST',
                json: { locals: vars.locals, action: vars.action },
            }),
        onSuccess: async (_r, vars) => {
            await refreshAfterMailboxWrite(queryClient);
            toast.show({
                kind: 'success',
                text: t(vars.action === 'disable' ? 'bulk.disabled' : 'bulk.enabled', { count: vars.locals.length }),
            });
            setSelected(new Set());
        },
        onError: (e) => toast.show({ kind: 'danger', text: errorText(e, t('bulk.failed')) }),
    });

    const toggle = useMutation({
        mutationFn: (m: Mailbox) =>
            apiFetch<undefined>(`${mailboxesPath(domain, m.local)}/${m.status === 'active' ? 'disable' : 'enable'}`, {
                method: 'POST',
            }),
        onSuccess: async (_r, m) => {
            await refreshAfterMailboxWrite(queryClient);
            toast.show({ kind: 'success', text: t(m.status === 'active' ? 'toggle.disabled' : 'toggle.enabled', { address: shown(m) }) });
        },
        onError: (e) => toast.show({ kind: 'danger', text: errorText(e, t('toggle.failed')) }),
    });

    const runBulk = (action: 'disable' | 'enable') => once(() => bulk.mutateAsync({ action, locals: selectedLocals }));

    const rowMenu = (m: Mailbox) => {
        const items: MenuItem[] = [
            { label: t('list.edit'), onSelect: () => openEditMailbox(m.local) },
            { label: t('list.resetPassword'), onSelect: () => openEditMailbox(m.local, 'password') },
            {
                label: m.status === 'active' ? t('list.disable') : t('list.enable'),
                disabled: toggle.isPending,
                onSelect: () => once(() => toggle.mutateAsync(m)),
            },
            { label: t('list.delete'), danger: true, onSelect: () => setDeleting(m) },
        ];
        return <Menu aria-label={t('list.actionsFor', { address: shown(m) })} items={items} />;
    };

    const addressCell = (m: Mailbox) => (
        <button
            type="button"
            onClick={() => openEditMailbox(m.local)}
            className="block max-w-full min-w-0 rounded text-left focus-visible:outline-2 focus-visible:outline-teal"
        >
            <span className={cx('block wrap-anywhere', m.status === 'active' ? 'text-ink' : 'text-muted')}>{shown(m)}</span>
            {m.display_name && <span className="block text-xs text-muted">{m.display_name}</span>}
        </button>
    );

    const storageCell = (m: Mailbox) => (
        <StorageBar used={m.used_bytes} limit={m.quota_bytes} disabled={m.status === 'disabled'} caption={storageCaption(m)} />
    );

    const sortGlyph = sort === 'address' ? '▲' : '▼';
    const sortButton = (className: string, label: string) => (
        <button
            type="button"
            aria-label={t('list.sortLabel', { order: sort === 'address' ? t('list.sortAsc') : t('list.sortDesc') })}
            onClick={() => setParam('sort', sort === 'address' ? '-address' : null)}
            className={cx('inline-flex items-center gap-1 rounded focus-visible:outline-2 focus-visible:outline-teal', className)}
        >
            {label} <span aria-hidden="true" className="text-teal-dark">{sortGlyph}</span>
        </button>
    );

    const phoneSort = sortButton('text-xs font-semibold text-teal-dark', t('list.sortPhone'));

    const columns: DataTableColumn<Mailbox>[] = [
        { key: 'address', header: sortButton('font-semibold text-ink', t('list.sortAddress')), cell: addressCell },
        { key: 'storage', header: t('list.columnStorage'), width: '200px', cell: storageCell },
        { key: 'forwarding', header: t('list.columnForwarding'), width: '100px', cell: (m) => m.forwarding_count },
        { key: 'status', header: t('list.columnStatus'), width: '110px', cell: (m) => <StatusChip m={m} /> },
        {
            key: 'actions',
            header: <span className="sr-only">{t('list.actions')}</span>,
            width: '60px',
            align: 'right',
            cell: rowMenu,
        },
    ];

    const card = (m: Mailbox) => (
        <div className={cx(panelClass, 'flex flex-col gap-2 px-3.5 py-3')}>
            <div className="flex items-start gap-2.5">
                <div className="min-w-0 flex-1">
                    <div className={cx('text-sm font-semibold wrap-anywhere', m.status === 'active' ? 'text-ink' : 'text-muted')}>
                        {shown(m)}
                    </div>
                    <div className="text-xs text-muted">
                        {t('list.card', {
                            name: m.display_name ?? t('list.noDisplayName'),
                            forwarding:
                                m.forwarding_count === 0
                                    ? t('list.noForwarding')
                                    : t('list.forwardingCount', { count: m.forwarding_count }),
                        })}
                    </div>
                </div>
                <StatusChip m={m} />
            </div>
            {storageCell(m)}
            <div className="flex gap-2">
                <Button className="flex-1" onClick={() => openEditMailbox(m.local)} aria-label={t('list.editNamed', { address: shown(m) })}>
                    {t('list.edit')}
                </Button>
                {rowMenu(m)}
            </div>
        </div>
    );

    const filtered = q !== '' || status !== 'all';
    const domainTotal = named?.mailbox_count;
    const addButton = (
        <Button variant="primary" onClick={openNewMailbox}>
            {t('list.add')}
        </Button>
    );
    const empty = !filtered ? (
        <EmptyState title={t('empty.title', { domain: unicode })} action={addButton}>
            {t('empty.hint')}
        </EmptyState>
    ) : (
        <EmptyState
            title={t('empty.noMatch')}
            action={
                <div className="flex justify-center gap-2">
                    <Button onClick={clearFilters}>{t('empty.clear')}</Button>
                    {addButton}
                </div>
            }
        >
            {domainTotal === undefined ? t('empty.tryFewer') : t('empty.searched', { count: domainTotal, domain: unicode })}
        </EmptyState>
    );

    const failed = list.isError && !list.data;
    const searchLabel = domainTotal === undefined ? t('list.search') : t('list.searchCount', { count: domainTotal });

    return (
        <div className={cx('flex flex-col', !phone && panelClass)}>
            <div className="flex items-center gap-2 md:gap-2.5 md:border-b md:border-line md:px-[18px] md:py-3.5">
                <input
                    ref={searchRef}
                    type="search"
                    aria-label={t('list.search')}
                    placeholder={phone ? t('list.searchPhone') : searchLabel}
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    className={cx(controlClass, 'border-line-strong md:h-9 md:w-[260px] md:text-[13.5px] max-md:flex-1')}
                />
                <select
                    aria-label={t('list.status')}
                    value={status}
                    onChange={(e) => setParam('status', e.target.value === 'all' ? null : e.target.value)}
                    className={cx(controlClass, 'w-auto border-line-strong font-semibold text-body md:h-9 md:text-[13px] max-md:max-w-[110px]')}
                >
                    <option value="all">{phone ? t('list.statusAllPhone') : t('list.statusAll')}</option>
                    <option value="active">{t('status.active')}</option>
                    <option value="disabled">{t('status.disabled')}</option>
                </select>
                <div className="flex-1 max-md:hidden" />
                {phone ? (
                    <Button variant="primary" aria-label={t('list.add')} className="w-12 flex-none text-xl" onClick={openNewMailbox}>
                        +
                    </Button>
                ) : (
                    <Button variant="primary" onClick={openNewMailbox}>
                        {t('list.addPlus')}
                    </Button>
                )}
            </div>
            {phone && list.data && (
                <div className="mt-3 flex items-center justify-between px-0.5 text-xs text-muted">
                    <span>{list.data.meta.total === 0 ? '' : mailboxCount(list.data.meta.total)}</span>
                    {phoneSort}
                </div>
            )}
            {!phone && selectedLocals.length > 0 && (
                <div className="flex items-center gap-3.5 border-b border-line bg-tint px-[18px] py-2 text-[13px]">
                    <span className="font-bold">{t('bulk.selected', { count: selectedLocals.length })}</span>
                    <Button variant="link" size="sm" disabled={bulk.isPending} onClick={() => setLimitOpen(true)}>
                        {t('bulk.changeLimit')}
                    </Button>
                    <Button variant="link" size="sm" busy={bulk.isPending && bulk.variables?.action === 'disable'} disabled={bulk.isPending} onClick={() => runBulk('disable')}>
                        {t('bulk.disable')}
                    </Button>
                    <Button variant="link" size="sm" busy={bulk.isPending && bulk.variables?.action === 'enable'} disabled={bulk.isPending} onClick={() => runBulk('enable')}>
                        {t('bulk.enable')}
                    </Button>
                    <Button variant="link" size="sm" className="ml-auto text-muted" onClick={() => setSelected(new Set())}>
                        {t('bulk.clear')}
                    </Button>
                </div>
            )}
            {failed ? (
                <div role="alert" className="flex items-center gap-3 px-4 py-6 text-[13px] text-danger">
                    <span className="min-w-0 flex-1">{errorText(list.error, t('list.loadFailed'))}</span>
                    <Button size="sm" onClick={() => void list.refetch()}>
                        {t('list.retry')}
                    </Button>
                </div>
            ) : (
                <div className="max-md:mt-2">
                    <DataTable
                        rows={rows}
                        rowKey={(m) => m.local}
                        columns={columns}
                        card={card}
                        loading={list.isPending}
                        empty={empty}
                        selectable={{ selected, onChange: setSelected }}
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
            <BulkLimitDialog
                open={limitOpen}
                domain={domain}
                locals={selectedLocals}
                onClose={() => setLimitOpen(false)}
                onDone={() => setSelected(new Set())}
            />
            {deleting && (
                <DeleteMailboxDialog
                    open
                    domain={domain}
                    local={deleting.local}
                    address={shown(deleting)}
                    onClose={() => setDeleting(null)}
                    onDeleted={() => searchRef.current?.focus()}
                />
            )}
        </div>
    );
}
