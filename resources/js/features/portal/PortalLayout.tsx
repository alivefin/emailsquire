import { useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Link, NavLink, Outlet, useNavigate } from 'react-router';
import { apiFetch } from '../../api/client';
import { Button } from '../../components/ui/Button';
import { cx } from '../../components/ui/cx';
import { LanguageSwitch } from '../../components/ui/LanguageSwitch';
import { PortalPage } from './PortalCard';
import { displayAddress, usePortalMe } from './usePortal';

const PAGES = [
    { to: '/my', label: 'nav.home', end: true },
    { to: '/my/password', label: 'nav.password', end: false },
    { to: '/my/forwarding', label: 'nav.forwarding', end: false },
    { to: '/my/aliases', label: 'nav.aliases', end: false },
    { to: '/my/settings', label: 'nav.settings', end: false },
    { to: '/my/help', label: 'nav.help', end: false },
] as const;

/**
 * The mailbox portal's frame, phone first: a header with the address, the language switch and Sign out,
 * then the six pages. On a phone the pages are a row that scrolls sideways under the header; from tablet
 * width up they are a column beside the page. Nothing here leads to the console.
 */
export function PortalLayout() {
    const { t } = useTranslation('portal');
    const me = usePortalMe().data;
    const queryClient = useQueryClient();
    const navigate = useNavigate();

    async function signOut() {
        try {
            await apiFetch('/auth/logout', { method: 'POST' });
        } catch {
            // Whatever the outcome, this browser leaves the portal.
        }
        queryClient.clear();
        void navigate('/signin', { replace: true });
    }

    const address = me ? displayAddress(me) : '';

    return (
        <div className="min-h-dvh bg-canvas text-ink">
            <header className="border-b border-line bg-white">
                <div className="mx-auto flex h-16 max-w-[1040px] items-center gap-3 px-4 md:h-[72px] md:px-6">
                    <span aria-hidden="true" className="size-[30px] flex-none rounded-md bg-linear-135 from-teal to-teal-dark max-md:hidden" />
                    <span className="min-w-0 flex-1 truncate text-[15px] font-bold md:text-base" title={address}>
                        {address}
                    </span>
                    <span className="flex-none text-sm">
                        <LanguageSwitch />
                    </span>
                    <Button size="sm" className="flex-none" onClick={() => void signOut()}>
                        {t('layout.signOut')}
                    </Button>
                </div>
            </header>
            <div className="mx-auto max-w-[1040px] md:flex md:items-start md:gap-6 md:px-6 md:py-6">
                <nav
                    aria-label={t('nav.label')}
                    className="border-b border-line bg-white md:sticky md:top-6 md:w-[200px] md:flex-none md:rounded-[10px] md:border md:p-2"
                >
                    <ul className="flex gap-1 overflow-x-auto px-3 py-2 md:flex-col md:overflow-visible md:p-0">
                        {PAGES.map((page) => (
                            <li key={page.to} className="flex-none">
                                <NavLink
                                    to={page.to}
                                    end={page.end}
                                    className={({ isActive }) =>
                                        cx(
                                            'flex h-11 items-center rounded-md px-3 text-sm font-semibold whitespace-nowrap',
                                            'focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-teal',
                                            isActive ? 'bg-tint text-teal-dark' : 'text-body hover:bg-subtle',
                                        )
                                    }
                                >
                                    {t(page.label)}
                                </NavLink>
                            </li>
                        ))}
                    </ul>
                </nav>
                <main className="min-w-0 flex-1 p-4 md:p-0">
                    <Outlet />
                </main>
            </div>
        </div>
    );
}

/** An unknown `/my` page: the console's "Page not found" would link to a dashboard this session can't open. */
export function PortalNotFound() {
    const { t } = useTranslation('portal');
    return (
        <PortalPage title={t('layout.notFound')}>
            <Link to="/my" className="font-semibold text-teal-dark hover:underline">
                {t('layout.backHome')}
            </Link>
        </PortalPage>
    );
}
