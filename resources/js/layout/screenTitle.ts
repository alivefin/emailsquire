import i18next from 'i18next';
import { useTranslation } from 'react-i18next';
import { matchPath, useLocation } from 'react-router';
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
    return t('nav.notFound');
}

/** The title for the current route; domain pages show the unicode name once the names have loaded. */
export function useScreenTitle(): string {
    // Subscribes this hook to language changes, since `screenTitle` reads the language at call time.
    useTranslation('common');
    const { pathname } = useLocation();
    const names = useDomainNames();
    const title = screenTitle(pathname);
    if (matchPath('/domains/:domain/*', pathname) === null) return title;
    return names.data?.find((d) => d.name === title)?.unicode_name ?? title;
}
