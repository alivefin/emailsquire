import { useId, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiFetch } from '../../api/client';
import { ApiProblem, fieldErrors, problemMessage } from '../../api/problems';
import { queryKeys, type Json } from '../../api/types';
import { Button } from '../../components/ui/Button';
import { cx } from '../../components/ui/cx';
import { DataTable, type DataTableColumn } from '../../components/ui/DataTable';
import { EmptyState } from '../../components/ui/EmptyState';
import { borderFor, controlClass } from '../../components/ui/fieldStyles';
import { Menu } from '../../components/ui/Menu';
import { Pager } from '../../components/ui/Pager';
import { useToast } from '../../components/ui/Toast';
import { formatBytes } from '../../lib/format';
import { pageParam, useClampPage, useDebouncedSearchParam, useGoToPage } from '../../lib/listParams';
import { useDomainNames } from '../../layout/useNavData';
import { DeleteDomainDialog } from './DeleteDomainDialog';

type DomainList = Json<'/domains', 'get'>;
type DomainRow = DomainList['data'][number];

const PER_PAGE = 10;
const panelClass = 'rounded-[10px] border border-line bg-white';
const FORMAT_MESSAGE = 'Enter a domain like example.org, without spaces, @ or /.';

function normalize(raw: string): string {
    return raw.trim().toLowerCase();
}

/** Instant feedback only; the server stays the authority on what a valid domain is. */
function formatProblem(name: string): string | null {
    if (name === '') return null;
    if (!name.includes('.') || /[\s@/]/.test(name)) return FORMAT_MESSAGE;
    return null;
}

const domainPath = (name: string) => `/domains/${encodeURIComponent(name)}/mailboxes`;

function DomainName({ d }: { d: DomainRow }) {
    return (
        <div className="min-w-0">
            <Link to={domainPath(d.name)} className="font-semibold text-ink [overflow-wrap:anywhere] hover:underline">
                {d.unicode_name}
            </Link>
            {d.name !== d.unicode_name && <div className="text-xs text-muted [overflow-wrap:anywhere]">{d.name}</div>}
        </div>
    );
}

const storage = (d: DomainRow) => (d.used_bytes === null ? '—' : formatBytes(d.used_bytes));

function AddDomainForm({ onClose }: { onClose: () => void }) {
    const inputId = useId();
    const navigate = useNavigate();
    const toast = useToast();
    const queryClient = useQueryClient();
    const names = useDomainNames();
    const [value, setValue] = useState('');
    const [touched, setTouched] = useState(false);
    const [serverError, setServerError] = useState<string | undefined>();

    const name = normalize(value);
    const duplicate = (names.data ?? []).some((d) => d.name === name || d.unicode_name === name);
    const clientError = formatProblem(name) ?? (duplicate ? 'That domain already exists.' : null);
    const blocked = name === '' || clientError !== null;
    const shown = serverError ?? (touched ? (clientError ?? undefined) : undefined);

    const add = useMutation({
        mutationFn: () => apiFetch<Json<'/domains', 'post'>>('/domains', { method: 'POST', json: { name } }),
        onSuccess: async (created) => {
            await Promise.all([
                queryClient.invalidateQueries({ queryKey: ['domains'] }),
                queryClient.invalidateQueries({ queryKey: queryKeys.dashboard }),
            ]);
            toast.show({ kind: 'success', text: `Added ${created.unicode_name}.` });
            void navigate(domainPath(created.name));
        },
        onError: (e) => {
            setServerError(
                fieldErrors(e).name ?? (e instanceof ApiProblem ? problemMessage(e) : 'The domain could not be added.'),
            );
        },
    });

    function submit() {
        setTouched(true);
        if (blocked || add.isPending) return;
        setServerError(undefined);
        add.mutate();
    }

    return (
        <form
            noValidate
            onSubmit={(e) => {
                e.preventDefault();
                submit();
            }}
            className="flex flex-col gap-2 border-b border-line bg-tint px-3.5 py-3 md:flex-row md:items-start md:px-[18px]"
        >
            <div className="md:max-w-[360px] md:flex-1">
                <label htmlFor={inputId} className="sr-only">
                    Domain name
                </label>
                <input
                    id={inputId}
                    value={value}
                    onChange={(e) => {
                        setValue(e.target.value);
                        setServerError(undefined);
                    }}
                    onBlur={() => setTouched(true)}
                    placeholder="example.org"
                    spellCheck={false}
                    autoComplete="off"
                    autoFocus
                    aria-invalid={shown ? true : undefined}
                    aria-describedby={shown ? `${inputId}-msg` : undefined}
                    className={cx(controlClass, borderFor(shown))}
                />
                <p id={`${inputId}-msg`} role={shown ? 'alert' : undefined} className="mt-1.5 min-h-4 text-[12.5px] text-danger">
                    {shown}
                </p>
            </div>
            <div className="flex gap-2">
                <Button type="submit" variant="dark" size="lg" disabled={blocked} busy={add.isPending}>
                    Add domain
                </Button>
                <Button size="lg" onClick={onClose}>
                    Cancel
                </Button>
            </div>
        </form>
    );
}

export function DomainsPage() {
    const [params, setParams] = useSearchParams();
    const navigate = useNavigate();
    const q = params.get('q') ?? '';
    const page = pageParam(params);
    const [search, setSearch] = useDebouncedSearchParam();
    const goToPage = useGoToPage();
    const [adding, setAdding] = useState(false);
    const [deleting, setDeleting] = useState<DomainRow | null>(null);

    const list = useQuery({
        queryKey: queryKeys.domains({ q, page }),
        queryFn: () =>
            apiFetch<DomainList>('/domains', { query: { q: q || undefined, page, limit: PER_PAGE } }),
        placeholderData: (previous) => previous,
    });

    useClampPage(page, list.data?.meta.last_page, !list.isPlaceholderData);

    function clearSearch() {
        setSearch('');
        setParams({}, { replace: true });
    }

    const rowMenu = (d: DomainRow) => (
        <Menu aria-label={`Actions for ${d.unicode_name}`} items={[{ label: 'Delete…', danger: true, onSelect: () => setDeleting(d) }]} />
    );

    const columns: DataTableColumn<DomainRow>[] = [
        { key: 'domain', header: 'Domain', cell: (d) => <DomainName d={d} /> },
        { key: 'mailboxes', header: 'Mailboxes', width: '110px', cell: (d) => d.mailbox_count },
        { key: 'forwarding', header: 'Forwarding', width: '110px', cell: (d) => d.forwarding_count },
        { key: 'storage', header: 'Storage used', width: '140px', cell: storage },
        {
            key: 'actions',
            header: <span className="sr-only">Actions</span>,
            width: '140px',
            align: 'right',
            cell: (d) => (
                <div className="flex items-center justify-end gap-1.5">
                    <Button size="sm" onClick={() => void navigate(domainPath(d.name))} aria-label={`Open ${d.unicode_name}`}>
                        Open
                    </Button>
                    {rowMenu(d)}
                </div>
            ),
        },
    ];

    const card = (d: DomainRow) => (
        <div className={cx(panelClass, 'flex items-start gap-2 px-3.5 py-3')}>
            <div className="min-w-0 flex-1">
                <DomainName d={d} />
                <div className="mt-1 text-xs text-muted">
                    {d.mailbox_count} mailboxes · {d.forwarding_count} forwarding · {storage(d)}
                </div>
            </div>
            {rowMenu(d)}
        </div>
    );

    const empty =
        q === '' ? (
            <EmptyState title="No domains yet">Add a domain to start creating mailboxes.</EmptyState>
        ) : (
            <EmptyState
                title={`No domain matches “${q}”`}
                action={
                    <Button variant="link" onClick={clearSearch}>
                        Clear search
                    </Button>
                }
            />
        );

    const failed = list.isError && !list.data;

    return (
        <div className={cx(panelClass, 'flex flex-col')}>
            <div className="flex flex-col gap-2.5 border-b border-line px-3.5 py-3 md:flex-row md:items-center md:px-[18px] md:py-3.5">
                <input
                    type="search"
                    aria-label="Search domains"
                    placeholder="Search domains"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    className={cx(controlClass, 'border-line-strong md:w-[260px]')}
                />
                <div className="md:ml-auto">
                    {!adding && (
                        <Button variant="primary" size="lg" className="max-md:w-full" onClick={() => setAdding(true)}>
                            + Add domain
                        </Button>
                    )}
                </div>
            </div>
            {adding && <AddDomainForm onClose={() => setAdding(false)} />}
            {failed ? (
                <div role="alert" className="flex items-center gap-3 px-4 py-6 text-[13px] text-danger">
                    <span className="min-w-0 flex-1">
                        {list.error instanceof ApiProblem ? problemMessage(list.error) : 'The domains could not be loaded.'}
                    </span>
                    <Button size="sm" onClick={() => void list.refetch()}>
                        Retry
                    </Button>
                </div>
            ) : (
                <div className="max-md:p-3">
                    <DataTable
                        rows={list.data?.data ?? []}
                        rowKey={(d) => d.name}
                        columns={columns}
                        card={card}
                        loading={list.isPending}
                        empty={empty}
                    />
                </div>
            )}
            {list.data && (
                <Pager
                    className="px-3.5 py-3 md:px-[18px]"
                    page={list.data.meta.page}
                    perPage={list.data.meta.per_page}
                    total={list.data.meta.total}
                    onPage={goToPage}
                />
            )}
            {deleting && (
                <DeleteDomainDialog
                    open
                    domain={deleting}
                    onClose={() => setDeleting(null)}
                />
            )}
        </div>
    );
}
