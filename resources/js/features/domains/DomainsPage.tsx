import { useId, useRef, useState } from 'react';
import i18next from 'i18next';
import { useTranslation } from 'react-i18next';
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
import { canManageDomains } from '../../lib/roles';
import { useDomainNames } from '../../layout/useNavData';
import { useMe } from '../auth/useMe';
import { DeleteDomainDialog } from './DeleteDomainDialog';

type DomainList = Json<'/domains', 'get'>;
type DomainRow = DomainList['data'][number];

const PER_PAGE = 10;
const panelClass = 'rounded-[10px] border border-line bg-white';

function normalize(raw: string): string {
    return raw.trim().toLowerCase();
}

/** Instant feedback only; the server stays the authority on what a valid domain is. */
function formatProblem(name: string): string | null {
    if (name === '') return null;
    if (!name.includes('.') || /[\s@/]/.test(name)) return i18next.t('formatHint', { ns: 'domains' });
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

/** "LK" for "Laura Kivi", "M" for "mia@example.org": first and last word, one letter each. */
function initials(name: string): string {
    const first = (word: string) => Array.from(word)[0] ?? '';
    if (!/\s/.test(name.trim()) && name.includes('@')) return first(name).toUpperCase();
    const words = name.trim().split(/\s+/).filter((w) => w !== '');
    if (words.length === 0) return '';
    const last = words.length > 1 ? first(words[words.length - 1]!) : '';
    return (first(words[0]!) + last).toUpperCase();
}

/** Up to three initials circles, each titled with the name, and "+N" for the rest. */
function People({ d }: { d: DomainRow }) {
    const { t } = useTranslation('domains');
    if (d.people_count === 0) return <span className="text-muted">—</span>;
    const more = d.people_count - d.people.length;
    return (
        <div className="flex items-center">
            <span className="sr-only">
                {t('peopleList', { people: t('peopleCount', { count: d.people_count }), names: d.people.join(', ') })}
            </span>
            {d.people.map((name, i) => (
                <span
                    key={`${name}-${i}`}
                    aria-hidden="true"
                    title={name}
                    className="-ml-1.5 grid size-7 flex-none place-items-center rounded-full bg-tint text-[11px] font-bold text-teal-dark ring-2 ring-white first:ml-0"
                >
                    {initials(name)}
                </span>
            ))}
            {more > 0 && (
                <span aria-hidden="true" className="ml-1.5 text-xs font-semibold text-muted">
                    {t('peopleMore', { count: more })}
                </span>
            )}
        </div>
    );
}

function AddDomainForm({ onClose }: { onClose: () => void }) {
    const { t } = useTranslation('domains');
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
    const clientError = formatProblem(name) ?? (duplicate ? t('exists') : null);
    const blocked = name === '' || clientError !== null;
    const shown = serverError ?? (touched ? (clientError ?? undefined) : undefined);

    const add = useMutation({
        mutationFn: () => apiFetch<Json<'/domains', 'post'>>('/domains', { method: 'POST', json: { name } }),
        onSuccess: async (created) => {
            await Promise.all([
                queryClient.invalidateQueries({ queryKey: ['domains'] }),
                queryClient.invalidateQueries({ queryKey: queryKeys.dashboard }),
            ]);
            toast.show({ kind: 'success', text: t('added', { name: created.unicode_name }) });
            void navigate(domainPath(created.name));
        },
        onError: (e) => {
            setServerError(
                fieldErrors(e).name ?? (e instanceof ApiProblem ? problemMessage(e) : t('addFailed')),
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
                    {t('nameLabel')}
                </label>
                <input
                    id={inputId}
                    value={value}
                    onChange={(e) => {
                        setValue(e.target.value);
                        setServerError(undefined);
                    }}
                    onBlur={() => setTouched(true)}
                    placeholder={t('namePlaceholder')}
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
                    {t('addDomain')}
                </Button>
                <Button size="lg" onClick={onClose}>
                    {t('cancel')}
                </Button>
            </div>
        </form>
    );
}

export function DomainsPage() {
    const { t } = useTranslation('domains');
    const [params, setParams] = useSearchParams();
    const navigate = useNavigate();
    const q = params.get('q') ?? '';
    const page = pageParam(params);
    const [search, setSearch] = useDebouncedSearchParam();
    const goToPage = useGoToPage();
    const [adding, setAdding] = useState(false);
    const [deleting, setDeleting] = useState<DomainRow | null>(null);
    // Adding and deleting domains is for server admins; hidden until `me` says so.
    const admin = canManageDomains(useMe().data);
    // Where focus goes after a delete takes away the row whose menu opened the dialog.
    const searchRef = useRef<HTMLInputElement>(null);

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

    const rowMenu = (d: DomainRow) =>
        admin ? (
            <Menu aria-label={t('actionsFor', { name: d.unicode_name })} items={[{ label: t('delete'), danger: true, onSelect: () => setDeleting(d) }]} />
        ) : null;

    const columns: DataTableColumn<DomainRow>[] = [
        { key: 'domain', header: t('columns.domain'), cell: (d) => <DomainName d={d} /> },
        { key: 'mailboxes', header: t('columns.mailboxes'), width: '100px', cell: (d) => d.mailbox_count },
        { key: 'forwarding', header: t('columns.forwarding'), width: '100px', cell: (d) => d.forwarding_count },
        { key: 'people', header: t('columns.people'), width: '120px', cell: (d) => <People d={d} /> },
        { key: 'storage', header: t('columns.storage'), width: '130px', cell: storage },
        {
            key: 'actions',
            header: <span className="sr-only">{t('columns.actions')}</span>,
            width: '140px',
            align: 'right',
            cell: (d) => (
                <div className="flex items-center justify-end gap-1.5">
                    <Button size="sm" onClick={() => void navigate(domainPath(d.name))} aria-label={t('openNamed', { name: d.unicode_name })}>
                        {t('open')}
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
                    {t('summary', {
                        mailboxes: t('mailboxCount', { count: d.mailbox_count }),
                        forwarding: t('forwardingCount', { count: d.forwarding_count }),
                        storage: storage(d),
                    })}
                </div>
                {d.people_count > 0 && <div className="text-xs text-muted">{t('peopleCount', { count: d.people_count })}</div>}
            </div>
            {rowMenu(d)}
        </div>
    );

    const empty =
        q === '' ? (
            <EmptyState title={t('empty.title')}>{admin && t('empty.hint')}</EmptyState>
        ) : (
            <EmptyState
                title={t('empty.noMatch', { q })}
                action={
                    <Button variant="link" onClick={clearSearch}>
                        {t('empty.clear')}
                    </Button>
                }
            />
        );

    const failed = list.isError && !list.data;

    return (
        <div className={cx(panelClass, 'flex flex-col')}>
            <div className="flex flex-col gap-2.5 border-b border-line px-3.5 py-3 md:flex-row md:items-center md:px-[18px] md:py-3.5">
                <input
                    ref={searchRef}
                    type="search"
                    aria-label={t('search')}
                    placeholder={t('search')}
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    className={cx(controlClass, 'border-line-strong md:w-[260px]')}
                />
                <div className="md:ml-auto">
                    {admin && !adding && (
                        <Button variant="primary" size="lg" className="max-md:w-full" onClick={() => setAdding(true)}>
                            {t('addDomainPlus')}
                        </Button>
                    )}
                </div>
            </div>
            {admin && adding && <AddDomainForm onClose={() => setAdding(false)} />}
            {failed ? (
                <div role="alert" className="flex items-center gap-3 px-4 py-6 text-[13px] text-danger">
                    <span className="min-w-0 flex-1">
                        {list.error instanceof ApiProblem ? problemMessage(list.error) : t('loadFailed')}
                    </span>
                    <Button size="sm" onClick={() => void list.refetch()}>
                        {t('retry')}
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
                    onDeleted={() => searchRef.current?.focus()}
                />
            )}
        </div>
    );
}
