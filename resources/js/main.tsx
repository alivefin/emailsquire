import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { RouterProvider } from 'react-router';
import { router } from './router';
import { ToastProvider } from './components/ui/Toast';
import { retryQuery } from './api/retry';
import { detectLocale, initI18n } from './i18n';

const queryClient = new QueryClient({ defaultOptions: { queries: { retry: retryQuery } } });

const root = document.getElementById('app');
if (root) {
    await initI18n(detectLocale());
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
