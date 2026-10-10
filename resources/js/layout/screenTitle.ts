import { matchPath } from 'react-router';

export function screenTitle(pathname: string): string {
    const domain = matchPath('/domains/:domain/*', pathname);
    if (domain?.params.domain) return domain.params.domain;
    if (pathname === '/') return 'Dashboard';
    if (pathname.startsWith('/domains')) return 'Domains';
    if (pathname.startsWith('/addresses')) return 'All addresses';
    if (pathname.startsWith('/blocked')) return 'Blocked addresses';
    return 'Page not found';
}
