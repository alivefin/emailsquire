import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { RouterProvider } from 'react-router';
import { createRouter } from './router';
import { ToastProvider } from './components/ui/Toast';
import { refreshAccessOnRefusal } from './api/accessRefresh';
import { retryQuery } from './api/retry';
import { detectLocale, initI18n } from './i18n';
import { installHost } from './modules/host';
import { loadModules, readModuleList } from './modules/load';

const queryClient = new QueryClient({ defaultOptions: { queries: { retry: retryQuery } } });
// A refused domain write means the role changed: refetch the roles so stale controls drop.
refreshAccessOnRefusal(queryClient);

const root = document.getElementById('app');
if (root) {
    await initI18n(detectLocale());
    // Modules register their routes and catalogues as their entries run, so the router waits for them.
    installHost(queryClient);
    await loadModules(readModuleList(), { timeoutMs: 5000 });
    const router = createRouter();
    createRoot(root).render(
        <StrictMode>
            <QueryClientProvider client={queryClient}>
                <ToastProvider>
                    <RouterProvider router={router} />
                </ToastProvider>
            </QueryClientProvider>
        </StrictMode>,
    );
}
