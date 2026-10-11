import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { apiFetch, type ApiInit } from '../../api/client';
import { useIsPhone } from '../../lib/useIsPhone';
import { routes } from '../../router';
import { fakeServer, type AuditFixtureRow } from '../../test/domainServer';
import { renderRoutes } from '../../test/routing';

vi.mock('../../api/client', () => ({ apiFetch: vi.fn(), onSessionExpired: vi.fn(() => () => {}), onTwoFactorRequired: vi.fn(() => () => {}), onStepUpRequired: vi.fn(() => () => {}), ensureCsrf: vi.fn() }));
vi.mock('../../lib/useIsPhone', () => ({ useIsPhone: vi.fn(() => false) }));

const BUCHER = 'xn--bcher-kva.example';

function row(id: number, over: Partial<AuditFixtureRow> = {}): AuditFixtureRow {
    return {
        id,
        occurred_at: new Date(Date.now() - id * 60_000).toISOString(),
        actor_kind: 'user',
        actor_name: 'Mia Manager',
        action: 'mailbox.create',
        object_type: 'mailbox',
        object_key: `user${id}@bücher.example`,
        domain_name: BUCHER,
        changes: null,
        ...over,
    };
}

beforeEach(() => {
    vi.mocked(apiFetch).mockReset();
    vi.mocked(useIsPhone).mockReturnValue(false);
});

function auditCalls() {
    return vi.mocked(apiFetch).mock.calls.filter(([path]) => path === '/audit-log');
}

describe('DomainActivityTab', () => {
    it('adds an Activity tab beside Mailboxes, Forwarding and Access', async () => {
        fakeServer();
        renderRoutes(routes, `/domains/${BUCHER}/mailboxes`);
        const tabs = await screen.findByRole('navigation', { name: 'Domain sections' });
        expect(within(tabs).getByRole('link', { name: 'Activity' })).toHaveAttribute('href', `/domains/${BUCHER}/activity`);
    });

    it("lists the domain's latest 20 entries and links to the activity log filtered by the domain", async () => {
        fakeServer({ audit: Array.from({ length: 25 }, (_, i) => row(i + 1)) });
        renderRoutes(routes, `/domains/${BUCHER}/activity`);

        expect(await screen.findByText('created mailbox user1@bücher.example', { exact: false })).toBeInTheDocument();
        const items = screen.getAllByRole('listitem').filter((li) => li.textContent?.includes('created mailbox'));
        expect(items).toHaveLength(20);
        expect(items[0]).toHaveTextContent('Mia Manager');
        expect(auditCalls().at(-1)?.[1]).toMatchObject({ query: { domain: BUCHER, per_page: 20 } });

        const link = screen.getByRole('link', { name: 'Open in the activity log →' });
        expect(link).toHaveAttribute('href', `/activity?domain=${BUCHER}`);
    });

    it('says so when the domain has no activity', async () => {
        fakeServer({ audit: [row(1, { domain_name: 'example.org' })] });
        renderRoutes(routes, `/domains/${BUCHER}/activity`);
        expect(await screen.findByText('No activity on this domain yet')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Open in the activity log →' })).toBeInTheDocument();
    });

    it('keeps the Activity tab when switching domains', async () => {
        const user = userEvent.setup();
        fakeServer();
        const { router } = renderRoutes(routes, `/domains/${BUCHER}/activity`);
        const header = await screen.findByRole('banner');
        await within(header).findByText('2 / 3');
        await user.click(within(header).getByRole('button', { name: 'Next domain' }));
        await waitFor(() => expect(router.state.location.pathname).toBe('/domains/zeta.example/activity'));
    });

    it("never shows the previous domain's rows while the next domain's load", async () => {
        const user = userEvent.setup();
        fakeServer({ audit: [row(1)] });
        const served = vi.mocked(apiFetch).getMockImplementation()!;
        // zeta.example's rows never arrive: the tab must show its loading state, not bücher.example's rows.
        vi.mocked(apiFetch).mockImplementation(((path: string, init?: ApiInit) =>
            path === '/audit-log' && init?.query?.domain === 'zeta.example' ? new Promise(() => {}) : served(path, init)) as typeof apiFetch);
        const { router } = renderRoutes(routes, `/domains/${BUCHER}/activity`);
        expect(await screen.findByText('created mailbox user1@bücher.example', { exact: false })).toBeInTheDocument();

        const header = await screen.findByRole('banner');
        await user.click(within(header).getByRole('button', { name: 'Next domain' }));
        await waitFor(() => expect(router.state.location.pathname).toBe('/domains/zeta.example/activity'));
        expect(await screen.findByLabelText('Loading activity')).toBeInTheDocument();
        expect(screen.queryByText('created mailbox user1@bücher.example', { exact: false })).not.toBeInTheDocument();
    });

    it('shows the activity log from the sidebar to someone who only views a domain', async () => {
        fakeServer({ role: 'viewer' });
        renderRoutes(routes, `/domains/${BUCHER}/activity`);
        const nav = await screen.findByRole('navigation', { name: 'Main' });
        expect(await within(nav).findByRole('link', { name: 'Activity' })).toHaveAttribute('href', '/activity');
    });
});
