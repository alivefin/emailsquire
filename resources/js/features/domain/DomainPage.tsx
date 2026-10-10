import { useMemo, useState } from 'react';
import { Link, NavLink, Outlet, useMatch, useNavigate, useParams } from 'react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiFetch } from '../../api/client';
import { queryKeys, type Json } from '../../api/types';
import { Button } from '../../components/ui/Button';
import { cx } from '../../components/ui/cx';
import { Menu } from '../../components/ui/Menu';
import { useToast } from '../../components/ui/Toast';
import { useIsPhone } from '../../lib/useIsPhone';
import { HeaderSlot } from '../../layout/headerSlot';
import { useDomainNames, usePinnedDomains, type DomainName } from '../../layout/useNavData';
import { DeleteDomainDialog } from '../domains/DeleteDomainDialog';
import { DomainActionsContext, errorText, useSingleFlight, type DomainActions, type DomainDrawer } from './shared';

type DomainList = Json<'/domains', 'get'>;

const arrowClass =
    'grid flex-none place-items-center rounded-lg border border-line-strong bg-white text-body hover:bg-subtle ' +
    'focus-visible:outline-2 focus-visible:outline-teal disabled:opacity-40';

/** Layout route for `/domains/:domain/*`. Keyed by the domain so switching domains starts fresh. */
export function DomainPage() {
    const { domain = '' } = useParams();
    return <DomainView key={domain} domain={domain} />;
}

/** The domain's row from `GET /domains`, for the counts the names list does not carry. */
function useDomainRow(domain: string) {
    return useQuery({
        queryKey: queryKeys.domains({ q: domain, limit: 200 }),
        queryFn: () => apiFetch<DomainList>('/domains', { query: { q: domain, limit: 200 } }),
        select: (r) => r.data.find((d) => d.name === domain),
    });
}

function DomainView({ domain }: { domain: string }) {
    const phone = useIsPhone();
    const navigate = useNavigate();
    const names = useDomainNames();
    const row = useDomainRow(domain);
    const pin = usePinToggle(domain);
    const [deleting, setDeleting] = useState(false);
    // Tasks 11 and 12 render their drawers from this state.
    const [, setDrawer] = useState<DomainDrawer>(null);

    const actions = useMemo<DomainActions>(
        () => ({
            openNewMailbox: () => setDrawer({ kind: 'new' }),
            openEditMailbox: (local, focus) => setDrawer({ kind: 'edit', local, focus }),
        }),
        [],
    );

    const list = names.data ?? [];
    const index = list.findIndex((d) => d.name === domain);
    const current = list[index];

    if (names.data && !current) {
        return (
            <div>
                <h2 className="text-lg font-bold wrap-anywhere">{domain} is not on this server.</h2>
                <Link to="/domains" className="mt-2 inline-block font-semibold text-teal-dark">
                    Back to domains
                </Link>
            </div>
        );
    }

    const mailboxTotal = row.data?.mailbox_count ?? current?.mailbox_count;
    const forwardingTotal = row.data?.forwarding_count;
    const openDelete = () => setDeleting(true);

    return (
        <DomainActionsContext.Provider value={actions}>
            {current && (
                <HeaderSlot>
                    {phone ? (
                        <PhoneSwitcher current={current} list={list} index={index} />
                    ) : (
                        <DesktopSwitcher current={current} list={list} index={index} pin={pin} />
                    )}
                </HeaderSlot>
            )}
            <div className="flex items-end gap-4 border-b border-line max-md:-mx-3 max-md:px-3 md:gap-6">
                <nav aria-label="Domain sections" className="flex min-w-0 gap-4 overflow-x-auto whitespace-nowrap md:gap-6">
                    <Tab to="mailboxes" label="Mailboxes" count={mailboxTotal} />
                    <Tab to="forwarding" label="Forwarding" count={forwardingTotal} />
                </nav>
                <div className="flex-1" />
                {phone ? (
                    <Menu
                        aria-label="Domain actions"
                        className="mb-1"
                        items={[
                            { label: pin.pinned ? 'Unpin' : 'Pin', onSelect: pin.toggle, disabled: !pin.ready },
                            { label: 'Delete domain…', danger: true, onSelect: openDelete, disabled: !row.data },
                        ]}
                    />
                ) : (
                    <Button variant="danger" size="sm" className="mb-2" disabled={!row.data} onClick={openDelete}>
                        Delete domain…
                    </Button>
                )}
            </div>
            <Outlet />
            {deleting && row.data && (
                <DeleteDomainDialog
                    open
                    domain={row.data}
                    onClose={() => setDeleting(false)}
                    onDeleted={() => void navigate('/domains')}
                />
            )}
        </DomainActionsContext.Provider>
    );
}

function Tab({ to, label, count }: { to: string; label: string; count: number | undefined }) {
    return (
        <NavLink
            to={to}
            className={({ isActive }) =>
                cx(
                    'border-b-2 px-1 pb-3 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-teal max-md:pt-1 max-md:pb-2.5',
                    isActive ? 'border-teal text-ink' : 'border-transparent text-muted hover:text-ink',
                )
            }
        >
            {count === undefined ? (
                label
            ) : (
                <>
                    {`${label} `}
                    <span className="font-medium text-muted">{count}</span>
                </>
            )}
        </NavLink>
    );
}

/** Navigates to another domain, keeping the current tab and dropping the list's search and filters. */
function useGoToDomain() {
    const navigate = useNavigate();
    const onForwarding = useMatch('/domains/:domain/forwarding') !== null;
    return (name: string) => void navigate(`/domains/${encodeURIComponent(name)}/${onForwarding ? 'forwarding' : 'mailboxes'}`);
}

interface SwitcherProps {
    current: DomainName;
    list: DomainName[];
    index: number;
}

function Arrows({ list, index, size }: { list: DomainName[]; index: number; size: string }) {
    const go = useGoToDomain();
    const step = (by: number) => {
        const next = list[(index + by + list.length) % list.length];
        if (next) go(next.name);
    };
    const single = list.length < 2;
    return (
        <>
            <button type="button" aria-label="Previous domain" disabled={single} onClick={() => step(-1)} className={cx(arrowClass, size)}>
                <span aria-hidden="true">‹</span>
            </button>
            <button type="button" aria-label="Next domain" disabled={single} onClick={() => step(1)} className={cx(arrowClass, size)}>
                <span aria-hidden="true">›</span>
            </button>
        </>
    );
}

function DesktopSwitcher({ current, list, index, pin }: SwitcherProps & { pin: PinToggle }) {
    const go = useGoToDomain();
    return (
        <>
            <Link to="/domains" className="text-[13px] font-semibold text-muted hover:underline">
                Domains
            </Link>
            <span aria-hidden="true" className="text-line-strong">
                /
            </span>
            <div className="flex h-[42px] min-w-0 items-center gap-2 rounded-lg border border-line-strong pr-1.5 pl-3">
                <select
                    aria-label="Domain"
                    value={current.name}
                    onChange={(e) => go(e.target.value)}
                    className="max-w-[260px] min-w-0 truncate border-0 bg-transparent text-lg font-bold outline-none"
                >
                    {list.map((d) => (
                        <option key={d.name} value={d.name}>
                            {d.unicode_name}
                        </option>
                    ))}
                </select>
                <span className="text-xs font-medium whitespace-nowrap text-muted">
                    {index + 1} / {list.length}
                </span>
            </div>
            <Arrows list={list} index={index} size="size-[42px]" />
            <Button aria-pressed={pin.pinned} disabled={!pin.ready} busy={pin.busy} onClick={pin.toggle}>
                {pin.pinned ? 'Pinned' : 'Pin'}
            </Button>
        </>
    );
}

function PhoneSwitcher({ current, list, index }: SwitcherProps) {
    return (
        <>
            <span className="min-w-0 flex-1 truncate">{current.unicode_name}</span>
            <Arrows list={list} index={index} size="size-10" />
        </>
    );
}

interface PinToggle {
    pinned: boolean;
    /** False until the pinned list has loaded, so the toggle never guesses. */
    ready: boolean;
    busy: boolean;
    toggle: () => void;
}

function usePinToggle(domain: string): PinToggle {
    const queryClient = useQueryClient();
    const toast = useToast();
    const pinnedList = usePinnedDomains();
    const once = useSingleFlight();
    const pinned = pinnedList.data?.some((d) => d.name === domain) ?? false;

    const mutation = useMutation({
        mutationFn: (on: boolean) =>
            apiFetch<undefined>(`/me/pinned-domains/${encodeURIComponent(domain)}`, { method: on ? 'PUT' : 'DELETE' }),
        // Stay busy until the pinned list has been refetched, so the label never shows a stale state.
        onSuccess: () => queryClient.invalidateQueries({ queryKey: queryKeys.pinned }),
        onError: (e) => toast.show({ kind: 'danger', text: errorText(e, 'The pin could not be changed.') }),
    });

    return {
        pinned,
        ready: pinnedList.isSuccess,
        busy: mutation.isPending,
        toggle: () => once(() => mutation.mutateAsync(!pinned)),
    };
}
