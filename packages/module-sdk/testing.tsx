import type { ReactElement } from 'react';
import { render } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router';
import { queryKeys } from '../../resources/js/api/types';
import type { Me } from '../../resources/js/features/auth/useMe';
import { ToastProvider } from '../../resources/js/components/ui/Toast';
import { initI18n } from '../../resources/js/i18n';
import { installHost, type ModuleDefinition } from '../../resources/js/modules/host';
import { registerCatalogues } from '../../resources/js/modules/registry';

export type RenderWithHostOptions = {
    /** The signed-in admin `useMe()` returns; omitted, nobody is signed in. */
    me?: Me;
    /** Where the memory router starts. */
    route?: string;
    /** The module under test, so `t('<name>:<file>.<key>')` resolves; `name` and `catalogues` as in its `registerModule()` call. */
    module?: Pick<ModuleDefinition, 'name' | 'catalogues'>;
};

/**
 * Renders a module's component the way the console does: a real query client (retries off), a memory router,
 * the toast provider, i18next with the core's catalogues plus the module's, and the host installed on
 * `globalThis.__emailsquire`.
 */
export async function renderWithHost(ui: ReactElement, { me, route = '/', module }: RenderWithHostOptions = {}) {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    if (me !== undefined) client.setQueryData(queryKeys.me, me);
    installHost(client);
    await initI18n('en');
    if (module) registerCatalogues(module.name, module.catalogues);
    const utils = render(
        <QueryClientProvider client={client}>
            <ToastProvider>
                <MemoryRouter initialEntries={[route]}>{ui}</MemoryRouter>
            </ToastProvider>
        </QueryClientProvider>,
    );
    return { ...utils, client };
}
