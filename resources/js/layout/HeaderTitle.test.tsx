import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import { apiFetch } from '../api/client';
import { useIsPhone } from '../lib/useIsPhone';
import { renderRoutes } from '../test/routing';
import { AppLayout } from './AppLayout';
import { HeaderSlot } from './headerSlot';

vi.mock('../api/client', () => ({ apiFetch: vi.fn() }));
vi.mock('../lib/useIsPhone', () => ({ useIsPhone: vi.fn() }));
vi.mock('../features/auth/useMe', async (importOriginal) => ({
    ...(await importOriginal<typeof import('../features/auth/useMe')>()),
    useMe: () => ({ data: { id: 1, name: 'Anna Admin', email: 'a@example.org', is_server_admin: true } }),
}));

const DOMAINS = [{ name: 'xn--bcher-kva.example', unicode_name: 'bücher.example', mailbox_count: 1 }];

function page(withSlot: boolean) {
    return [
        {
            element: <AppLayout />,
            children: [
                {
                    path: '/domains/:domain/mailboxes',
                    element: withSlot ? (
                        <HeaderSlot>
                            <span>Switcher</span>
                        </HeaderSlot>
                    ) : (
                        <p>page</p>
                    ),
                },
            ],
        },
    ];
}
const PATH = '/domains/xn--bcher-kva.example/mailboxes';

beforeEach(() => {
    vi.mocked(apiFetch).mockReset();
    vi.mocked(apiFetch).mockImplementation((path: string) =>
        Promise.resolve(path === '/dashboard' ? { counts: { mailboxes: 0, forwarding: 0 }, server_storage: null } : { data: DOMAINS }),
    );
});

describe.each([
    ['desktop', false],
    ['phone', true],
])('domain page title on %s', (_name, phone) => {
    beforeEach(() => vi.mocked(useIsPhone).mockReturnValue(phone));

    it('shows the unicode name', async () => {
        renderRoutes(page(false), PATH);
        expect(await screen.findByText('bücher.example', { selector: 'header span.truncate' })).toBeInTheDocument();
        expect(screen.queryByText('xn--bcher-kva.example', { selector: 'header span.truncate' })).not.toBeInTheDocument();
    });

    it('replaces the fallback title with HeaderSlot content', async () => {
        renderRoutes(page(true), PATH);
        expect(await screen.findByText('Switcher')).toBeInTheDocument();
        expect(screen.queryByText('bücher.example', { selector: 'header span.truncate' })).not.toBeInTheDocument();
        expect(screen.queryByText('xn--bcher-kva.example', { selector: 'header span.truncate' })).not.toBeInTheDocument();
    });
});
