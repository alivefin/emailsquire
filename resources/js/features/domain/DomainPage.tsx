import { useMemo, useState } from 'react';
import { Link, NavLink, Outlet, useMatch, useNavigate, useParams } from 'react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import i18next from 'i18next';
import { useTranslation } from 'react-i18next';
import { apiFetch } from '../../api/client';
import { queryKeys, type Json } from '../../api/types';
import { Button } from '../../components/ui/Button';
import { cx } from '../../components/ui/cx';
import { Menu, type MenuItem } from '../../components/ui/Menu';
import { useToast } from '../../components/ui/Toast';
import { formatBytesOf } from '../../lib/format';
import { canManageDomains, canWrite } from '../../lib/roles';
import { useIsPhone } from '../../lib/useIsPhone';
import { HeaderSlot } from '../../layout/headerSlot';
import { useDomainNames, usePinnedDomains, type DomainName } from '../../layout/useNavData';
import { useMe } from '../auth/useMe';
import { DeleteDomainDialog } from '../domains/DeleteDomainDialog';
import { DomainSettingsDialog } from './DomainSettingsDialog';
import { EditMailboxDrawer } from './EditMailboxDrawer';
import { NewMailboxDrawer } from './NewMailboxDrawer';
import { DomainActionsContext, errorText, useSingleFlight, type DomainActions, type DomainDrawer } from './shared';

type DomainList = Json<'/domains', 'get'>;
type DomainRow = DomainList['data'][number];

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

/** "12 / 20 mailboxes · 48 / 100 GB allocated", with only the limits the domain has; null when it has none. */
function usageText(row: DomainRow | undefined): string | null {
    if (!row) return null;
    const t = (key: string, options: Record<string, unknown>) => i18next.t(key, { ...options, ns: 'domains' });
    const parts: string[] = [];
    if (row.max_mailboxes !== null) parts.push(t('header.mailboxUsage', { used: row.mailbox_count, count: row.max_mailboxes }));
    if (row.max_quota_bytes_total !== null) {
        parts.push(t('header.quotaUsage', { amount: formatBytesOf(row.quota_bytes_allocated, row.max_quota_bytes_total) }));
    }
    return parts.length === 0 ? null : parts.join(' · ');
}

function DomainView({ domain }: { domain: string }) {
    const { t } = useTranslation(['mailboxes', 'domains', 'access', 'activity', 'dns']);
    const phone = useIsPhone();
    const me = useMe();
    const navigate = useNavigate();
    const names = useDomainNames();
    const row = useDomainRow(domain);
    const pin = usePinToggle(domain);
    const [deleting, setDeleting] = useState(false);
    const [settingsOpen, setSettingsOpen] = useState(false);
    const [drawer, setDrawer] = useState<DomainDrawer>(null);

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
                <h2 className="text-lg font-bold wrap-anywhere">{t('page.notOnServer', { domain })}</h2>
                <Link to="/domains" className="mt-2 inline-block font-semibold text-teal-dark">
                    {t('page.back')}
                </Link>
            </div>
        );
    }

    const mailboxTotal = row.data?.mailbox_count ?? current?.mailbox_count;
    const forwardingTotal = row.data?.forwarding_count;
    const openDelete = () => setDeleting(true);
    const openSettings = () => setSettingsOpen(true);
    // Hidden, not disabled, for a role that can't use them; hidden too until the role is known.
    const role = current?.role;
    const viewOnly = role !== undefined && !canWrite(role);
    const admin = canManageDomains(me.data);
    const usage = usageText(row.data);

    const phoneItems: MenuItem[] = [{ label: pin.pinned ? t('page.unpin') : t('page.pin'), onSelect: pin.toggle, disabled: !pin.ready }];
    // Every role gets Domain settings: admins the limits and the mailbox owners' switches, managers the
    // switches, viewers the switches read-only.
    if (role !== undefined) phoneItems.push({ label: t('domains:settings.open'), onSelect: openSettings });
    if (admin) phoneItems.push({ label: t('page.deleteDomain'), danger: true, onSelect: openDelete, disabled: !row.data });

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
            {(viewOnly || usage) && (
                <p className="-mt-1 mb-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-[13px] text-muted max-md:mt-0 max-md:mb-2 max-md:text-xs">
                    {viewOnly && (
                        <span className="rounded-md bg-subtle px-2 py-0.5 font-semibold text-body">{t('domains:header.viewOnly')}</span>
                    )}
                    {usage && <span>{usage}</span>}
                </p>
            )}
            {/* Beside the sidebar the tabs keep their full width; when the admin buttons don't fit beside
                them (Finnish at 820 px), the buttons wrap onto a line above the tabs (wrap-reverse). Below
                1024 px the tabs sit closer, in 13 px text, so all five fit beside the sidebar in Finnish down
                to 768 px, with room to spare for Linux's slightly wider text. When the tabs still don't fit (Finnish with 3-digit counts), the bar scrolls sideways. Its 4 px
                padding, taken back by the negative margin, keeps room for a focused tab's outline, which an
                overflow box would otherwise clip, also on the first and last tab and on the top and bottom edges. */}
            <div className="flex items-end gap-4 border-b border-line max-md:-mx-3 max-md:px-3 md:flex-wrap-reverse md:gap-x-6 md:gap-y-1">
                <nav
                    aria-label={t('page.sections')}
                    className="-m-1 flex min-w-0 scroll-px-1 gap-4 overflow-x-auto p-1 whitespace-nowrap md:max-w-full md:shrink-0 md:gap-1.5 lg:gap-6"
                >
                    <Tab to="mailboxes" label={t('page.tabMailboxes')} count={mailboxTotal} />
                    <Tab to="forwarding" label={t('page.tabForwarding')} count={forwardingTotal} />
                    <Tab to="access" label={t('access:tab')} count={row.data?.people_count} />
                    <Tab to="dns" label={t('dns:tab')} count={undefined} />
                    <Tab to="activity" label={t('activity:tab')} count={undefined} />
                </nav>
                <div className="flex-1" />
                {phone ? (
                    <Menu aria-label={t('page.actions')} className="mb-1" items={phoneItems} />
                ) : (
                    role !== undefined && (
                        <div className="mb-2 ml-auto flex flex-none gap-2">
                            <Button size="sm" onClick={openSettings}>
                                {t('domains:settings.open')}
                            </Button>
                            {admin && (
                                <Button variant="danger" size="sm" disabled={!row.data} onClick={openDelete}>
                                    {t('page.deleteDomain')}
                                </Button>
                            )}
                        </div>
                    )
                )}
            </div>
            <Outlet />
            <NewMailboxDrawer open={drawer?.kind === 'new' && canWrite(role)} onClose={() => setDrawer(null)} domain={domain} />
            {drawer?.kind === 'edit' && (
                <EditMailboxDrawer open onClose={() => setDrawer(null)} domain={domain} local={drawer.local} focus={drawer.focus} />
            )}
            {settingsOpen && role !== undefined && (
                <DomainSettingsDialog
                    open
                    onClose={() => setSettingsOpen(false)}
                    domain={domain}
                    unicode={current?.unicode_name ?? domain}
                    role={role}
                />
            )}
            {deleting && admin && row.data && (
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
                    'border-b-2 px-1 pb-3 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-teal max-md:pt-1 max-md:pb-2.5 md:max-lg:px-0 md:max-lg:text-[13px]',
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
    const tab = useMatch('/domains/:domain/:tab')?.params.tab;
    const keep = tab === 'forwarding' || tab === 'access' || tab === 'dns' || tab === 'activity' ? tab : 'mailboxes';
    return (name: string) => void navigate(`/domains/${encodeURIComponent(name)}/${keep}`);
}

interface SwitcherProps {
    current: DomainName;
    list: DomainName[];
    index: number;
}

function Arrows({ list, index, size }: { list: DomainName[]; index: number; size: string }) {
    const { t } = useTranslation('mailboxes');
    const go = useGoToDomain();
    const step = (by: number) => {
        const next = list[(index + by + list.length) % list.length];
        if (next) go(next.name);
    };
    const single = list.length < 2;
    return (
        <>
            <button type="button" aria-label={t('page.previous')} disabled={single} onClick={() => step(-1)} className={cx(arrowClass, size)}>
                <span aria-hidden="true">‹</span>
            </button>
            <button type="button" aria-label={t('page.next')} disabled={single} onClick={() => step(1)} className={cx(arrowClass, size)}>
                <span aria-hidden="true">›</span>
            </button>
        </>
    );
}

function DesktopSwitcher({ current, list, index, pin }: SwitcherProps & { pin: PinToggle }) {
    const { t } = useTranslation('mailboxes');
    const go = useGoToDomain();
    return (
        <>
            {/* The sidebar's Domains item covers this link where the header is short of room. */}
            <Link to="/domains" className="text-[13px] font-semibold text-muted hover:underline max-xl:hidden">
                {t('page.breadcrumb')}
            </Link>
            <span aria-hidden="true" className="text-line-strong max-xl:hidden">
                /
            </span>
            {/* Shrinks with the header, but never below a 140 px select (160 px with its padding and border). */}
            <div className="flex h-[42px] min-w-[160px] items-center gap-2 rounded-lg border border-line-strong pr-1.5 pl-3">
                <select
                    aria-label={t('page.domainSelect')}
                    value={current.name}
                    onChange={(e) => go(e.target.value)}
                    className="w-full max-w-[260px] min-w-[140px] truncate border-0 bg-transparent text-lg font-bold outline-none"
                >
                    {list.map((d) => (
                        <option key={d.name} value={d.name}>
                            {d.unicode_name}
                        </option>
                    ))}
                </select>
                <span className="text-xs font-medium whitespace-nowrap text-muted max-lg:hidden">
                    {t('page.position', { index: index + 1, total: list.length })}
                </span>
            </div>
            <Arrows list={list} index={index} size="size-[42px]" />
            <Button aria-pressed={pin.pinned} disabled={!pin.ready} busy={pin.busy} onClick={pin.toggle}>
                {pin.pinned ? t('page.pinned') : t('page.pin')}
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
    const { t } = useTranslation('mailboxes');
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
        onError: (e) => toast.show({ kind: 'danger', text: errorText(e, t('page.pinFailed')) }),
    });

    return {
        pinned,
        ready: pinnedList.isSuccess,
        busy: mutation.isPending,
        toggle: () => once(() => mutation.mutateAsync(!pinned)),
    };
}
