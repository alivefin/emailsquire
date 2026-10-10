import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import { apiFetch } from '../../api/client';
import { setLocale } from '../../i18n';
import { renderRoutes } from '../../test/routing';
import { DashboardPage } from './DashboardPage';

vi.mock('../../api/client', () => ({ apiFetch: vi.fn() }));

const ADMIN = { id: 1, name: 'Anna Admin', email: 'anna@example.org', is_server_admin: true, managers_can_share: false, landing: null };

function serve(local: number, external: number, recent_activity: unknown[] = []) {
    const dashboard = {
        counts: { domains: 1, domains_added_this_month: 0, mailboxes: 1, forwarding: local + external, forwarding_local: local, forwarding_external: external },
        near_full: { items: [] },
        recent_activity,
        server_storage: null,
        usage_checked_at: null,
    };
    const names = { data: [{ name: 'example.org', unicode_name: 'example.org', mailbox_count: 1, role: 'admin' }] };
    vi.mocked(apiFetch).mockImplementation(((path: string) =>
        Promise.resolve(path === '/me' ? ADMIN : path === '/domains/names' ? names : dashboard)) as typeof apiFetch);
}

beforeEach(() => vi.mocked(apiFetch).mockReset());

describe('DashboardPage forwarding split in Finnish', () => {
    it.each([
        [1, 1, '1 paikallinen · 1 ulkoinen'],
        [2, 2, '2 paikallista · 2 ulkoista'],
        [0, 3, '0 paikallista · 3 ulkoista'],
    ])('inflects %i local and %i external', async (local, external, text) => {
        await setLocale('fi');
        serve(local, external);
        renderRoutes([{ path: '/', element: <DashboardPage /> }], '/');
        expect(await screen.findByText(text)).toBeInTheDocument();
    });
});

describe('DashboardPage activity in Finnish', () => {
    it('words your own action in Finnish, not "You loi"', async () => {
        await setLocale('fi');
        serve(0, 0, [
            {
                occurred_at: new Date().toISOString(),
                actor_kind: 'you',
                actor_name: 'You',
                action: 'mailbox.create',
                object_type: 'mailbox',
                object_key: 'anna@example.org',
                domain_name: 'example.org',
                changes: null,
            },
        ]);
        renderRoutes([{ path: '/', element: <DashboardPage /> }], '/');
        const row = (await screen.findByText('loit postilaatikon anna@example.org', { exact: false })).closest('li');
        expect(row).toHaveTextContent('Sinä loit postilaatikon anna@example.org');
        expect(row).not.toHaveTextContent('You');
    });
});

describe('DashboardPage layout', () => {
    // jsdom has no layout: Playwright's Finnish run at 820 px checks the fit. This pins the breakpoints
    // that fix it: two stat cards a row and stacked panels until 1024 px, and a wrapping button row.
    it('keeps two stat cards a row and stacks the panels below 1024 px', async () => {
        await setLocale('fi');
        serve(0, 0);
        renderRoutes([{ path: '/', element: <DashboardPage /> }], '/');
        await screen.findByRole('heading', { name: 'Viimeaikainen toiminta' });
        const statGrid = (await screen.findByText('Verkkotunnukset')).closest('a')?.parentElement;
        expect(statGrid?.className).toContain('grid-cols-2');
        expect(statGrid?.className).toContain('lg:grid-cols-4');
        expect(statGrid?.className).not.toContain('md:grid-cols-4');

        const panels = screen.getByRole('heading', { name: 'Viimeaikainen toiminta' }).closest('section')?.parentElement;
        expect(panels?.className).toMatch(/(^| )lg:grid-cols-/);
        expect(panels?.className).not.toMatch(/(^| )md:grid-cols-/);

        expect((await screen.findByRole('button', { name: 'Lisää edelleenlähetys' })).parentElement?.className).toContain('flex-wrap');
    });
});
