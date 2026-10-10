import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useSearchParams } from 'react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiFetch } from '../../api/client';
import { ApiProblem, fieldErrors, problemMessage } from '../../api/problems';
import { queryKeys, type Json } from '../../api/types';
import { Button } from '../../components/ui/Button';
import { cx } from '../../components/ui/cx';
import { DataTable, type DataTableColumn } from '../../components/ui/DataTable';
import { EmptyState } from '../../components/ui/EmptyState';
import { borderFor, controlClass } from '../../components/ui/fieldStyles';
import { Pager } from '../../components/ui/Pager';
import { useToast } from '../../components/ui/Toast';
import { formatRelative } from '../../lib/format';
import { pageParam, useClampPage, useDebouncedSearchParam, useGoToPage } from '../../lib/listParams';
import { isServerAdmin } from '../../lib/roles';
import { useMe } from '../auth/useMe';
import { errorText, useSingleFlight } from '../domain/shared';

type BlockedList = Json<'/blocked-addresses', 'get'>;
type BlockedRow = BlockedList['data'][number];

const PER_PAGE = 10;
const panelClass = 'rounded-[10px] border border-line bg-white';

/** The prototype's check, for instant feedback only. */
const looksLikeAddress = (a: string) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(a);

interface BlockInput {
    address: string;
    reason: string | null;
}

function useBlock() {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: (v: BlockInput) =>
            apiFetch<Json<'/blocked-addresses', 'post'>>('/blocked-addresses', {
                method: 'POST',
                json: v.reason === null ? { address: v.address } : { address: v.address, reason: v.reason },
            }),
        onSuccess: () =>
            Promise.all([
                queryClient.invalidateQueries({ queryKey: ['blocked'] }),
                queryClient.invalidateQueries({ queryKey: queryKeys.dashboard }),
            ]),
    });
}

function BlockForm() {
    const { t } = useTranslation('blocked');
    const addressId = useId();
    const reasonId = useId();
    const toast = useToast();
    const block = useBlock();
    const [address, setAddress] = useState('');
    const [reason, setReason] = useState('');
    const [clientError, setClientError] = useState<string | undefined>();
    const [serverError, setServerError] = useState<string | undefined>();
    const [reasonError, setReasonError] = useState<string | undefined>();

    const shown = clientError ?? serverError;

    function submit() {
        const a = address.trim().toLowerCase();
        if (!looksLikeAddress(a)) {
            setClientError(t('form.invalid'));
            return;
        }
        if (block.isPending) return;
        setClientError(undefined);
        setServerError(undefined);
        setReasonError(undefined);
        const r = reason.trim();
        block.mutate(
            { address: a, reason: r === '' ? null : r },
            {
                onSuccess: () => {
                    toast.show({ kind: 'success', text: t('form.blocked', { address: a }) });
                    setAddress('');
                    setReason('');
                },
                onError: (e) => {
                    const fields = fieldErrors(e);
                    if (fields.address) setServerError(fields.address);
                    else if (e instanceof ApiProblem && e.status === 409) setServerError(problemMessage(e));
                    else if (!fields.reason) setServerError(errorText(e, t('form.failed')));
                    if (fields.reason) setReasonError(fields.reason);
                },
            },
        );
    }

    return (
        <form
            noValidate
            onSubmit={(e) => {
                e.preventDefault();
                submit();
            }}
            className="flex flex-col gap-2 border-b border-line bg-tint px-3.5 py-3 md:px-[18px] md:py-3.5"
        >
            <div>
                <h2 className="text-sm font-bold text-ink md:text-[15px]">{t('form.title')}</h2>
                <p className="mt-0.5 hidden text-[12.5px] text-muted md:block">
                    {t('form.hint')}
                </p>
            </div>
            <div className="flex flex-col gap-2 md:flex-row md:items-start">
                <div className="md:w-[300px]">
                    <label htmlFor={addressId} className="sr-only">
                        {t('form.addressLabel')}
                    </label>
                    <input
                        id={addressId}
                        value={address}
                        onChange={(e) => {
                            setAddress(e.target.value);
                            setClientError(undefined);
                            setServerError(undefined);
                        }}
                        placeholder={t('form.addressPlaceholder')}
                        spellCheck={false}
                        autoComplete="off"
                        aria-invalid={shown ? true : undefined}
                        aria-describedby={shown ? `${addressId}-msg` : undefined}
                        className={cx(controlClass, borderFor(shown))}
                    />
                    <p id={`${addressId}-msg`} role={shown ? 'alert' : undefined} className="mt-1.5 min-h-4 text-[12.5px] text-danger">
                        {shown}
                    </p>
                </div>
                <div className="md:w-[300px]">
                    <label htmlFor={reasonId} className="sr-only">
                        {t('form.reasonLabel')}
                    </label>
                    <input
                        id={reasonId}
                        value={reason}
                        onChange={(e) => {
                            setReason(e.target.value);
                            setReasonError(undefined);
                        }}
                        placeholder={t('form.reasonPlaceholder')}
                        autoComplete="off"
                        aria-invalid={reasonError ? true : undefined}
                        aria-describedby={reasonError ? `${reasonId}-msg` : undefined}
                        className={cx(controlClass, borderFor(reasonError))}
                    />
                    <p id={`${reasonId}-msg`} role={reasonError ? 'alert' : undefined} className="mt-1.5 min-h-4 text-[12.5px] text-danger">
                        {reasonError}
                    </p>
                </div>
                <Button type="submit" variant="dark" size="lg" busy={block.isPending}>
                    {t('form.submit')}
                </Button>
            </div>
        </form>
    );
}

export function BlockedPage() {
    const { t } = useTranslation('blocked');
    const queryClient = useQueryClient();
    const toast = useToast();
    const once = useSingleFlight();
    const reblock = useBlock();
    const [params, setParams] = useSearchParams();
    const q = params.get('q') ?? '';
    const page = pageParam(params);
    const [search, setSearch] = useDebouncedSearchParam();
    const goToPage = useGoToPage();
    // Everyone may see the list; only a server admin blocks and unblocks.
    const admin = isServerAdmin(useMe().data);

    const list = useQuery({
        queryKey: queryKeys.blocked({ q, page }),
        queryFn: () => apiFetch<BlockedList>('/blocked-addresses', { query: { q: q || undefined, page, limit: PER_PAGE } }),
        placeholderData: (previous) => previous,
    });

    useClampPage(page, list.data?.meta.last_page, !list.isPlaceholderData);

    const unblock = useMutation({
        mutationFn: (row: BlockedRow) =>
            apiFetch<undefined>(`/blocked-addresses/${encodeURIComponent(row.address)}`, { method: 'DELETE' }),
        onSuccess: async (_r, row) => {
            await Promise.all([
                queryClient.invalidateQueries({ queryKey: ['blocked'] }),
                queryClient.invalidateQueries({ queryKey: queryKeys.dashboard }),
            ]);
            toast.show({
                kind: 'success',
                text: t('unblocked', { address: row.address }),
                action: {
                    label: t('undo'),
                    onClick: () =>
                        reblock.mutate(
                            { address: row.address, reason: row.reason },
                            { onError: (e) => toast.show({ kind: 'danger', text: errorText(e, t('reblockFailed')) }) },
                        ),
                },
            });
        },
        onError: (e) => toast.show({ kind: 'danger', text: errorText(e, t('unblockFailed')) }),
    });

    const unblockButton = (row: BlockedRow, className?: string) => (
        <Button
            size="sm"
            className={className}
            aria-label={t('unblockNamed', { address: row.address })}
            disabled={unblock.isPending}
            busy={unblock.isPending && unblock.variables?.address === row.address}
            onClick={() => once(() => unblock.mutateAsync(row))}
        >
            {t('unblock')}
        </Button>
    );

    const when = (row: BlockedRow) => (row.created_at ? formatRelative(row.created_at) : '—');
    const reasonText = (row: BlockedRow) => row.reason ?? '—';

    const columns: DataTableColumn<BlockedRow>[] = [
        { key: 'address', header: <span className="font-semibold text-ink">{t('columns.address')}</span>, cell: (r) => <span className="font-semibold wrap-anywhere">{r.address}</span> },
        { key: 'reason', header: t('columns.reason'), cell: (r) => <span className="text-body wrap-anywhere">{reasonText(r)}</span> },
        { key: 'by', header: t('columns.addedBy'), width: '150px', cell: (r) => r.created_by_name ?? '—' },
        { key: 'when', header: t('columns.when'), width: '110px', cell: (r) => <span className="text-[12.5px] text-muted">{when(r)}</span> },
    ];
    if (admin) {
        columns.push({ key: 'actions', header: <span className="sr-only">{t('columns.actions')}</span>, width: '100px', align: 'right', cell: (r) => unblockButton(r) });
    }

    const card = (r: BlockedRow) => (
        <div className={cx(panelClass, 'flex items-center gap-2.5 px-3.5 py-3')}>
            <div className="min-w-0 flex-1">
                <div className="text-sm font-semibold wrap-anywhere">{r.address}</div>
                <div className="text-xs text-muted wrap-anywhere">
                    {reasonText(r)} · {r.created_by_name ?? '—'} · {when(r)}
                </div>
            </div>
            {admin && unblockButton(r)}
        </div>
    );

    const empty =
        q === '' ? (
            <EmptyState title={t('empty.none')}>{admin && t('empty.noneHint')}</EmptyState>
        ) : (
            <EmptyState
                title={t('empty.noMatch', { q })}
                action={
                    <Button
                        onClick={() => {
                            setSearch('');
                            setParams({}, { replace: true });
                        }}
                    >
                        {t('empty.clear')}
                    </Button>
                }
            />
        );

    const failed = list.isError && !list.data;

    return (
        <div className={cx(panelClass, 'flex flex-col')}>
            {admin && <BlockForm />}
            <div className="border-b border-line px-3.5 py-3 md:px-[18px]">
                <input
                    type="search"
                    aria-label={t('search')}
                    placeholder={t('search')}
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    className={cx(controlClass, 'border-line-strong md:h-9 md:w-[260px] md:text-[13.5px]')}
                />
            </div>
            {failed ? (
                <div role="alert" className="flex items-center gap-3 px-4 py-6 text-[13px] text-danger">
                    <span className="min-w-0 flex-1">{errorText(list.error, t('loadFailed'))}</span>
                    <Button size="sm" onClick={() => void list.refetch()}>
                        {t('retry')}
                    </Button>
                </div>
            ) : (
                <div className="max-md:p-3">
                    <DataTable rows={list.data?.data ?? []} rowKey={(r) => r.address} columns={columns} card={card} loading={list.isPending} empty={empty} />
                </div>
            )}
            {list.data && list.data.meta.total > 0 && (
                <Pager
                    className="px-3.5 py-3 md:px-[18px]"
                    page={list.data.meta.page}
                    perPage={list.data.meta.per_page}
                    total={list.data.meta.total}
                    onPage={goToPage}
                />
            )}
        </div>
    );
}
