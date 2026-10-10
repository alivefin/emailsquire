import { Link, useNavigate, useSearchParams } from 'react-router';
import { useQuery } from '@tanstack/react-query';
import { apiFetch } from '../../api/client';
import { queryKeys, type Json } from '../../api/types';
import { Button } from '../../components/ui/Button';
import { Chip } from '../../components/ui/Chip';
import { cx } from '../../components/ui/cx';
import { DataTable, type DataTableColumn } from '../../components/ui/DataTable';
import { EmptyState } from '../../components/ui/EmptyState';
import { controlClass } from '../../components/ui/fieldStyles';
import { Pager } from '../../components/ui/Pager';
import { pageParam, useClampPage, useDebouncedSearchParam, useGoToPage } from '../../lib/listParams';
import { useIsPhone } from '../../lib/useIsPhone';
import { useDomainNames } from '../../layout/useNavData';
import { errorText } from '../domain/shared';

type AddressList = Json<'/addresses', 'get'>;
type AddressRow = AddressList['data'][number];
type TypeFilter = 'all' | 'mailbox' | 'forward';

const PER_PAGE = 10;
const panelClass = 'rounded-[10px] border border-line bg-white';
const selectClass = 'w-auto border-line-strong font-semibold text-body md:h-9 md:text-[13px]';

function typeParam(raw: string | null): TypeFilter {
    return raw === 'mailbox' || raw === 'forward' ? raw : 'all';
}

const localPart = (address: string) => address.slice(0, address.lastIndexOf('@'));

export function AddressesPage() {
    const phone = useIsPhone();
    const navigate = useNavigate();
    const names = useDomainNames();
    const [params, setParams] = useSearchParams();
    const q = params.get('q') ?? '';
    const type = typeParam(params.get('type'));
    const domain = params.get('domain') ?? '';
    const page = pageParam(params);
    const [search, setSearch] = useDebouncedSearchParam();
    const goToPage = useGoToPage();

    const list = useQuery({
        queryKey: queryKeys.addresses({ q, type, domain, page }),
        queryFn: () =>
            apiFetch<AddressList>('/addresses', {
                query: {
                    q: q || undefined,
                    type: type === 'all' ? undefined : type,
                    domain: domain || undefined,
                    page,
                    limit: PER_PAGE,
                },
            }),
        placeholderData: (previous) => previous,
    });

    useClampPage(page, list.data?.meta.last_page, !list.isPlaceholderData);

    function setParam(key: string, value: string | null) {
        setParams((prev) => {
            const next = new URLSearchParams(prev);
            if (value === null || value === '') next.delete(key);
            else next.set(key, value);
            next.delete('page');
            return next;
        });
    }

    function clearFilters() {
        setSearch('');
        setParams({}, { replace: true });
    }

    const unicodeOf = (name: string) => names.data?.find((d) => d.name === name)?.unicode_name ?? name;
    const shown = (a: AddressRow) => `${localPart(a.address)}@${unicodeOf(a.domain)}`;

    function open(a: AddressRow) {
        const local = localPart(a.address);
        const base = `/domains/${encodeURIComponent(a.domain)}`;
        const search = new URLSearchParams({ q: local });
        if (a.type === 'mailbox') {
            search.set('open', local);
            void navigate(`${base}/mailboxes?${search.toString()}`);
        } else {
            void navigate(`${base}/forwarding?${search.toString()}`);
        }
    }

    const typeChip = (a: AddressRow) =>
        a.type === 'mailbox' ? <Chip kind="mailbox">Mailbox</Chip> : <Chip kind="server">Forwarding</Chip>;
    const target = (a: AddressRow) => (a.targets && a.targets.length > 0 ? a.targets.join(', ') : '—');
    const openButton = (a: AddressRow, className?: string) => (
        <Button size="sm" className={className} onClick={() => open(a)} aria-label={`Open ${shown(a)}`}>
            Open
        </Button>
    );

    const columns: DataTableColumn<AddressRow>[] = [
        { key: 'address', header: <span className="font-semibold text-ink">Address</span>, cell: (a) => <span className="font-semibold wrap-anywhere">{shown(a)}</span> },
        { key: 'type', header: 'Type', width: '120px', cell: typeChip },
        { key: 'target', header: 'Delivers to', cell: (a) => <span className="text-[13px] text-body wrap-anywhere">{target(a)}</span> },
        { key: 'actions', header: <span className="sr-only">Actions</span>, width: '90px', align: 'right', cell: (a) => openButton(a) },
    ];

    const card = (a: AddressRow) => (
        <div className={cx(panelClass, 'flex flex-col gap-2 px-3.5 py-3')}>
            <div className="flex items-start gap-2">
                <div className="min-w-0 flex-1 text-sm font-semibold wrap-anywhere">{shown(a)}</div>
                {typeChip(a)}
            </div>
            {a.targets && a.targets.length > 0 && <div className="text-[12.5px] text-body wrap-anywhere">→ {target(a)}</div>}
            {openButton(a, 'w-full')}
        </div>
    );

    const filtered = q !== '' || type !== 'all' || domain !== '';
    const empty = filtered ? (
        <EmptyState
            title={q === '' ? 'Nothing matches' : `Nothing matches “${q}”`}
            action={
                <div className="flex justify-center gap-2">
                    <Button onClick={clearFilters}>Clear search</Button>
                    <Link
                        to="/blocked"
                        className="inline-flex h-9 items-center rounded-lg border border-line-strong px-3.5 text-[13px] font-semibold text-teal-dark max-md:h-12 max-md:text-sm"
                    >
                        Blocked addresses
                    </Link>
                </div>
            }
        >
            Try fewer characters, or check the blocked list.
        </EmptyState>
    ) : (
        <EmptyState
            title="No addresses yet"
            action={
                <Link to="/blocked" className="text-[13px] font-semibold text-teal-dark hover:underline">
                    Blocked addresses
                </Link>
            }
        >
            Mailboxes and forwarding addresses you add to a domain appear here.
        </EmptyState>
    );

    const failed = list.isError && !list.data;

    return (
        <div className={cx('flex flex-col', !phone && panelClass)}>
            <div className="flex flex-wrap items-center gap-2 md:gap-2.5 md:border-b md:border-line md:px-[18px] md:py-3.5">
                <input
                    type="search"
                    aria-label="Search addresses"
                    placeholder={phone ? 'Where does an address go?' : 'Where does an address go? Type any part of it'}
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    className={cx(controlClass, 'min-w-0 border-line-strong max-md:w-full md:h-9 md:w-[360px] md:text-[13.5px]')}
                />
                <select
                    aria-label="Type"
                    value={type}
                    onChange={(e) => setParam('type', e.target.value === 'all' ? null : e.target.value)}
                    className={cx(controlClass, selectClass, 'max-md:flex-1')}
                >
                    <option value="all">All</option>
                    <option value="mailbox">Mailboxes</option>
                    <option value="forward">Forwarding</option>
                </select>
                <select
                    aria-label="Domain"
                    value={domain}
                    onChange={(e) => setParam('domain', e.target.value)}
                    className={cx(controlClass, selectClass, 'max-w-[200px] max-md:flex-1')}
                >
                    <option value="">All domains</option>
                    {(names.data ?? []).map((d) => (
                        <option key={d.name} value={d.name}>
                            {d.unicode_name}
                        </option>
                    ))}
                </select>
                {list.data && (
                    <span className="text-xs text-muted md:ml-auto md:text-[12.5px]">
                        {list.data.meta.total} {list.data.meta.total === 1 ? 'address' : 'addresses'}
                    </span>
                )}
            </div>
            {failed ? (
                <div role="alert" className="flex items-center gap-3 px-4 py-6 text-[13px] text-danger">
                    <span className="min-w-0 flex-1">{errorText(list.error, 'The addresses could not be loaded.')}</span>
                    <Button size="sm" onClick={() => void list.refetch()}>
                        Retry
                    </Button>
                </div>
            ) : (
                <div className="max-md:mt-3">
                    <DataTable
                        rows={list.data?.data ?? []}
                        rowKey={(a) => a.address}
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
        </div>
    );
}
