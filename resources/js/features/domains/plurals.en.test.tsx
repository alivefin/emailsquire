import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import { apiFetch } from '../../api/client';
import { renderRoutes } from '../../test/routing';
import { DeleteDomainDialog } from './DeleteDomainDialog';
import { DomainsPage } from './DomainsPage';

vi.mock('../../api/client', () => ({ apiFetch: vi.fn() }));

beforeEach(() => vi.mocked(apiFetch).mockReset());

describe('English plurals', () => {
    it.each([
        [0, 0, '0 mailboxes and 0 forwarding addresses will be deleted.'],
        [1, 1, '1 mailbox and 1 forwarding address will be deleted.'],
        [2, 2, '2 mailboxes and 2 forwarding addresses will be deleted.'],
    ])('delete dialog for %i mailboxes and %i forwarding addresses', (mailboxes, forwarding, sentence) => {
        const domain = { name: 'example.org', unicode_name: 'example.org', mailbox_count: mailboxes, forwarding_count: forwarding };
        renderRoutes([{ path: '/', element: <DeleteDomainDialog open onClose={vi.fn()} domain={domain} /> }], '/');
        expect(screen.getByText(new RegExp(`^${sentence.replace('.', '\\.')}`))).toBeInTheDocument();
    });

    it.each([
        [0, '0 mailboxes · 0 forwarding · 0 KB'],
        [1, '1 mailbox · 1 forwarding · 0 KB'],
        [2, '2 mailboxes · 2 forwarding · 0 KB'],
    ])('domain card summary for %i', async (n, summary) => {
        vi.mocked(apiFetch).mockImplementation((p: string) =>
            Promise.resolve(
                p === '/domains'
                    ? { data: [{ name: 'example.org', unicode_name: 'example.org', mailbox_count: n, forwarding_count: n, used_bytes: 0 }], meta: { page: 1, per_page: 10, total: 1, last_page: 1 } }
                    : { data: [] },
            ),
        );
        const orig = window.matchMedia;
        window.matchMedia = ((q: string) => ({ matches: true, media: q, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {}, onchange: null, dispatchEvent: () => false })) as typeof window.matchMedia;
        try {
            renderRoutes([{ path: '/domains', element: <DomainsPage /> }], '/domains');
            expect(await screen.findByText(summary)).toBeInTheDocument();
        } finally {
            window.matchMedia = orig;
        }
    });
});
