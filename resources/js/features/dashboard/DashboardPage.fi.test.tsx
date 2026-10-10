import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import { apiFetch } from '../../api/client';
import { setLocale } from '../../i18n';
import { renderRoutes } from '../../test/routing';
import { DashboardPage } from './DashboardPage';

vi.mock('../../api/client', () => ({ apiFetch: vi.fn() }));

function serve(local: number, external: number) {
    vi.mocked(apiFetch).mockResolvedValue({
        counts: { domains: 1, domains_added_this_month: 0, mailboxes: 1, forwarding: local + external, forwarding_local: local, forwarding_external: external },
        near_full: { items: [] },
        recent_activity: [],
        server_storage: null,
        usage_checked_at: null,
    });
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
