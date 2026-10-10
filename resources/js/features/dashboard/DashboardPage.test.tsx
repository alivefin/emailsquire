import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router';
import { apiFetch } from '../../api/client';
import { ApiProblem } from '../../api/problems';
import { ToastProvider } from '../../components/ui/Toast';
import { testQueryClient } from '../../test/routing';
import { DashboardPage } from './DashboardPage';

vi.mock('../../api/client', () => ({ apiFetch: vi.fn() }));

const GIB = 1073741824;

function dashboard(over: Record<string, unknown> = {}) {
    return {
        counts: {
            domains: 100,
            domains_added_this_month: 2,
            mailboxes: 3214,
            forwarding: 4198,
            forwarding_local: 3120,
            forwarding_external: 1078,
        },
        near_full: {
            count: 1,
            items: [
                {
                    local: 'anna',
                    address: 'anna@example.org',
                    display_name: 'Anna Admin',
                    quota_bytes: 5 * GIB,
                    quota_is_default: true,
                    status: 'active',
                    used_bytes: 4.6 * GIB,
                    usage_checked_at: '2026-10-10T08:00:00Z',
                    forwarding_count: 0,
                    domain: 'example.org',
                },
            ],
        },
        recent_activity: [
            {
                occurred_at: new Date(Date.now() - 5 * 60_000).toISOString(),
                actor_kind: 'user',
                actor_name: 'Markus',
                action: 'mailbox.delete',
                object_type: 'mailbox',
                object_key: 'old@example.org',
                domain_name: 'example.org',
                changes: null,
            },
        ],
        server_storage: {
            total_bytes: 100 * GIB,
            free_bytes: 58 * GIB,
            used_bytes: 42 * GIB,
            checked_at: '2026-10-10T08:00:00Z',
        },
        usage_checked_at: new Date(Date.now() - 12 * 60_000).toISOString(),
        ...over,
    };
}

function renderPage() {
    return render(
        <QueryClientProvider client={testQueryClient()}>
            <ToastProvider>
                <MemoryRouter>
                    <DashboardPage />
                </MemoryRouter>
            </ToastProvider>
        </QueryClientProvider>,
    );
}

beforeEach(() => {
    vi.mocked(apiFetch).mockReset();
    vi.mocked(apiFetch).mockResolvedValue(dashboard());
});

describe('DashboardPage', () => {
    it('renders the four stat cards', async () => {
        renderPage();
        expect(await screen.findByText('+2 this month')).toBeInTheDocument();
        expect(screen.getByText('Domains')).toBeInTheDocument();
        expect(screen.getByText('Mailboxes')).toBeInTheDocument();
        expect(screen.getByText('Forwarding')).toBeInTheDocument();
        expect(screen.getByText('Server storage')).toBeInTheDocument();
        expect(screen.getByText('100')).toBeInTheDocument();
        expect(screen.getByText('3 214')).toBeInTheDocument();
        expect(screen.getByText('4 198')).toBeInTheDocument();
        expect(screen.getByText('3 120 local · 1 078 external')).toBeInTheDocument();
        expect(screen.getByText('42 GB of 100 GB')).toBeInTheDocument();
    });

    it('says Not checked yet when server_storage is null', async () => {
        vi.mocked(apiFetch).mockResolvedValue(dashboard({ server_storage: null, usage_checked_at: null }));
        renderPage();
        expect(await screen.findByText('Not checked yet')).toBeInTheDocument();
        expect(screen.getByText('Usage not checked yet')).toBeInTheDocument();
    });

    it('shows when usage was last checked', async () => {
        renderPage();
        expect(await screen.findByText('Usage checked 12 min ago')).toBeInTheDocument();
    });

    it('lists near-full mailboxes and opens the edit drawer on click', async () => {
        vi.mocked(apiFetch).mockImplementation(((path: string) => {
            if (path === '/domains/names') return Promise.resolve({ data: [{ name: 'example.org', unicode_name: 'example.org', mailbox_count: 1 }] });
            if (path === '/domains/example.org/mailboxes/anna') return Promise.resolve(dashboard().near_full.items[0]);
            if (path.endsWith('/usage') || path.endsWith('/forwarding')) return new Promise(() => {});
            return Promise.resolve(dashboard());
        }) as typeof apiFetch);
        renderPage();
        const row = await screen.findByRole('button', { name: /anna@example\.org/ });
        expect(within(row).getByRole('progressbar')).toBeInTheDocument();
        await userEvent.setup().click(row);
        const drawer = await screen.findByRole('dialog', { name: 'anna@example.org' });
        expect(await within(drawer).findByRole('button', { name: 'Save limit' })).toBeInTheDocument();
    });

    it('opens the new mailbox drawer with a domain select from Add mailbox', async () => {
        vi.mocked(apiFetch).mockImplementation(((path: string) =>
            Promise.resolve(
                path === '/domains/names'
                    ? { data: [{ name: 'xn--bcher-kva.example', unicode_name: 'bücher.example', mailbox_count: 1 }] }
                    : dashboard(),
            )) as typeof apiFetch);
        renderPage();
        const user = userEvent.setup();
        await user.click(await screen.findByRole('button', { name: 'Add mailbox' }));
        const drawer = screen.getByRole('dialog', { name: 'New mailbox' });
        expect(await within(drawer).findByRole('option', { name: 'bücher.example' })).toBeInTheDocument();
        expect(within(drawer).getByRole('combobox', { name: 'Domain' })).toHaveValue('xn--bcher-kva.example');
    });

    it('opens the forwarding drawer with a domain select from Add forwarding', async () => {
        vi.mocked(apiFetch).mockImplementation(((path: string) =>
            Promise.resolve(
                path === '/domains/names'
                    ? { data: [{ name: 'xn--bcher-kva.example', unicode_name: 'bücher.example', mailbox_count: 1 }] }
                    : path.endsWith('/mailboxes')
                      ? { data: [], meta: { page: 1, per_page: 200, total: 0, last_page: 1 } }
                      : dashboard(),
            )) as typeof apiFetch);
        renderPage();
        await userEvent.setup().click(await screen.findByRole('button', { name: 'Add forwarding' }));
        const drawer = screen.getByRole('dialog', { name: 'New forwarding address' });
        expect(await within(drawer).findByRole('option', { name: 'bücher.example' })).toBeInTheDocument();
        expect(within(drawer).getByRole('combobox', { name: 'Domain' })).toHaveValue('xn--bcher-kva.example');
    });

    it('shows recent activity as actor, sentence and time, with no activity-log link', async () => {
        renderPage();
        expect(await screen.findByText('Markus')).toBeInTheDocument();
        expect(screen.getByText('deleted mailbox old@example.org', { exact: false })).toBeInTheDocument();
        expect(screen.getByText('5 min ago')).toBeInTheDocument();
        expect(screen.queryByText(/activity log/i)).not.toBeInTheDocument();
    });

    it('shows empty states', async () => {
        vi.mocked(apiFetch).mockResolvedValue(
            dashboard({ near_full: { count: 0, items: [] }, recent_activity: [] }),
        );
        renderPage();
        expect(await screen.findByText('No mailboxes are close to their limit')).toBeInTheDocument();
        expect(screen.getByText('No activity yet')).toBeInTheDocument();
    });

    it('shows an inline error with Retry', async () => {
        vi.mocked(apiFetch).mockRejectedValueOnce(new ApiProblem({ status: 500, title: 'Server error' }));
        renderPage();
        expect(await screen.findByText('Server error')).toBeInTheDocument();
        await userEvent.setup().click(screen.getByRole('button', { name: 'Retry' }));
        expect(await screen.findByText('+2 this month')).toBeInTheDocument();
    });
});
