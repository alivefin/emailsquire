import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { RouterProvider } from 'react-router';
import { router } from './router';
import { ToastProvider } from './components/ui/Toast';
import { ApiProblem } from './api/problems';

// A 4xx will not change on retry, and a 401 has already sent the user to /signin. Retry other failures twice.
const queryClient = new QueryClient({
    defaultOptions: {
        queries: {
            retry: (failures, error) => !(error instanceof ApiProblem && error.status < 500) && failures < 2,
        },
    },
});

const root = document.getElementById('app');
if (root) {
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
