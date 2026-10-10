import { matchPath, useLocation } from 'react-router';
import { useDomainNames } from './useNavData';

export function screenTitle(pathname: string): string {
    const domain = matchPath('/domains/:domain/*', pathname);
    if (domain?.params.domain) return domain.params.domain;
    if (pathname === '/') return 'Dashboard';
    if (pathname.startsWith('/domains')) return 'Domains';
    if (pathname.startsWith('/addresses')) return 'All addresses';
    if (pathname.startsWith('/blocked')) return 'Blocked addresses';
    return 'Page not found';
}

/** The title for the current route; domain pages show the unicode name once the names have loaded. */
export function useScreenTitle(): string {
    const { pathname } = useLocation();
    const names = useDomainNames();
    const title = screenTitle(pathname);
    if (matchPath('/domains/:domain/*', pathname) === null) return title;
    return names.data?.find((d) => d.name === title)?.unicode_name ?? title;
}
