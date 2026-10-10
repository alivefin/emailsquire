import type { ReactNode } from 'react';
import { Trans, useTranslation } from 'react-i18next';
import { Link, useLocation } from 'react-router';
import { useLocale } from '../i18n';
import { formatBytes } from '../lib/format';
import { cx } from '../components/ui/cx';
import { useMe } from '../features/auth/useMe';
import { isServerAdmin } from '../lib/roles';
import { useDashboard, useDomainNames, usePinnedDomains } from './useNavData';

function NavItem({ to, active, children, count, countLabel, onNavigate }: { to: string; active: boolean; children: ReactNode; count?: number; countLabel?: string; onNavigate?: () => void }) {
    const locale = useLocale();
    return (
        <Link
            to={to}
            onClick={onNavigate}
            aria-current={active ? 'page' : undefined}
            className={cx(
                'flex items-center gap-3 rounded-lg px-3 py-2.5 text-ink focus-visible:outline-2 focus-visible:outline-teal',
                active ? 'bg-tint font-bold' : 'font-medium hover:bg-subtle',
            )}
        >
            <span aria-hidden="true" className={cx('size-4 flex-none rounded', active ? 'bg-teal' : 'bg-line-strong')} />
            {children}
            {count !== undefined && (
                <span title={countLabel} className="ml-auto font-medium text-muted">
                    {count.toLocaleString(locale)}
                </span>
            )}
        </Link>
    );
}

/** The navigation column; the phone bar reuses it inside a sheet. `onNavigate` fires when an item is chosen. */
export function Sidebar({ onNavigate }: { onNavigate?: () => void }) {
    const { t } = useTranslation('common');
    const { pathname } = useLocation();
    const names = useDomainNames();
    const pinned = usePinnedDomains();
    const dashboard = useDashboard();
    // Users and the server storage are for server admins only.
    const admin = isServerAdmin(useMe().data);

    const counts = dashboard.data?.counts;
    const storage = dashboard.data?.server_storage ?? null;
    const addressCount = counts ? counts.mailboxes + counts.forwarding : undefined;
    const pct = storage && storage.total_bytes > 0 ? Math.min(100, (storage.used_bytes / storage.total_bytes) * 100) : 0;
    const inDomain = (name: string) => pathname.startsWith(`/domains/${name}/`) || pathname === `/domains/${name}`;
    const onDomains = pathname === '/domains' || pathname.startsWith('/domains/');

    return (
        <nav aria-label={t('nav.main')} className="flex h-full flex-col overflow-y-auto bg-white px-4 pt-[22px] pb-4 text-sm">
            <div className="flex items-center gap-2.5 px-1 text-lg font-extrabold">
                <div aria-hidden="true" className="size-7 rounded-[7px] bg-linear-to-br from-teal to-teal-dark" />
                {t('app.name')}
            </div>
            <div className="mt-[22px] mb-1.5 px-1 text-[12.5px] text-muted">{t('nav.mainMenu')}</div>
            <NavItem to="/" active={pathname === '/'} onNavigate={onNavigate}>{t('nav.dashboard')}</NavItem>
            <NavItem to="/domains" active={onDomains} count={names.data?.length} countLabel={names.data ? t('nav.domainCount', { count: names.data.length }) : undefined} onNavigate={onNavigate}>{t('nav.domains')}</NavItem>
            <NavItem to="/addresses" active={pathname.startsWith('/addresses')} count={addressCount} countLabel={addressCount !== undefined ? t('nav.addressCount', { count: addressCount }) : undefined} onNavigate={onNavigate}>{t('nav.allAddresses')}</NavItem>
            <div className="mt-4 mb-1.5 px-1 text-[12.5px] text-muted">{t('nav.pinned')}</div>
            {(pinned.data ?? []).map((d) => (
                <Link
                    key={d.name}
                    to={`/domains/${d.name}/mailboxes`}
                    onClick={onNavigate}
                    className={cx(
                        'flex items-center gap-2.5 rounded-md px-3 py-2 text-ink focus-visible:outline-2 focus-visible:outline-teal',
                        inDomain(d.name) ? 'bg-tint font-bold' : 'font-medium hover:bg-subtle',
                    )}
                >
                    <span aria-hidden="true" className={cx('size-2 flex-none rounded-full', inDomain(d.name) ? 'bg-teal' : 'bg-line-strong')} />
                    <span className="truncate">{d.unicode_name}</span>
                    <span title={t('nav.mailboxCount', { count: d.mailbox_count })} className="ml-auto font-medium text-faint">
                        {d.mailbox_count}
                    </span>
                </Link>
            ))}
            <div className="mt-4 mb-1.5 px-1 text-[12.5px] text-muted">{t('nav.server')}</div>
            <NavItem to="/blocked" active={pathname.startsWith('/blocked')} onNavigate={onNavigate}>{t('nav.blocked')}</NavItem>
            {admin && (
                <NavItem to="/users" active={pathname.startsWith('/users')} onNavigate={onNavigate}>
                    {t('nav.users')}
                </NavItem>
            )}
            <div className="flex-1" />
            {admin && (
                <div className="mt-4 rounded-lg bg-ink p-3.5 text-[12.5px] text-white">
                    <div className="font-bold">{t('nav.storage')}</div>
                    {storage ? (
                        <>
                            <div className="mt-0.5 text-[#c7cbd1]">
                                <Trans
                                    t={t}
                                    i18nKey="nav.storageUsed"
                                    values={{ used: formatBytes(storage.used_bytes), total: formatBytes(storage.total_bytes) }}
                                    components={[<b key="used" className="text-teal" />]}
                                />
                            </div>
                            <div className="mt-2.5 h-1.5 rounded-full bg-[#3a3d44]">
                                <div className="h-full rounded-full bg-teal" style={{ width: `${pct}%` }} />
                            </div>
                        </>
                    ) : (
                        <div className="mt-0.5 text-[#c7cbd1]">{t('nav.notChecked')}</div>
                    )}
                </div>
            )}
        </nav>
    );
}
