import i18next from 'i18next';
import { useTranslation } from 'react-i18next';
import { matchPath, useLocation } from 'react-router';
import { useMe } from '../features/auth/useMe';
import { isServerAdmin } from '../lib/roles';
import { useDomainNames } from './useNavData';

export function screenTitle(pathname: string): string {
    const domain = matchPath('/domains/:domain/*', pathname);
    if (domain?.params.domain) return domain.params.domain;
    const t = (key: string) => i18next.t(key, { ns: 'common' });
    if (pathname === '/') return t('nav.dashboard');
    if (pathname.startsWith('/domains')) return t('nav.domains');
    if (pathname.startsWith('/addresses')) return t('nav.allAddresses');
    if (pathname.startsWith('/blocked')) return t('nav.blocked');
    if (pathname === '/profile') return t('nav.profile');
    if (pathname === '/activity') return t('nav.activity');
    if (pathname === '/users' || pathname.startsWith('/users/')) return t('nav.users');
    return t('nav.notFound');
}

/** The title for the current route; domain pages show the unicode name once the names have loaded. */
export function useScreenTitle(): string {
    // Subscribes this hook to language changes, since `screenTitle` reads the language at call time.
    useTranslation('common');
    const { pathname } = useLocation();
    const names = useDomainNames();
    const me = useMe();
    const title = screenTitle(pathname);
    // The Users screens don't exist for anyone but a server admin, who sees Page not found there.
    if (matchPath('/users/*', pathname) !== null && !isServerAdmin(me.data)) return i18next.t('nav.notFound', { ns: 'common' });
    if (matchPath('/domains/:domain/*', pathname) === null) return title;
    return names.data?.find((d) => d.name === title)?.unicode_name ?? title;
}
