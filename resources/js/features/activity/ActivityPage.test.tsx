import { beforeEach, describe, expect, it, vi } from 'vitest';
import { act, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { apiFetch, type ApiInit } from '../../api/client';
import { queryKeys } from '../../api/types';
import { useIsPhone } from '../../lib/useIsPhone';
import { LocationProbe, renderRoutes } from '../../test/routing';
import { inTimeZone } from '../../test/timeZone';
import type { AuditFixtureRow } from '../../test/domainServer';
import { ActivityPage } from './ActivityPage';

vi.mock('../../api/client', () => ({ apiFetch: vi.fn() }));
vi.mock('../../lib/useIsPhone', () => ({ useIsPhone: vi.fn(() => false) }));

const ADMIN = { id: 1, name: 'Anna Admin', email: 'anna@example.org', is_server_admin: true, managers_can_share: false, landing: null };
const MANAGER = { ...ADMIN, id: 2, name: 'Mia Manager', is_server_admin: false };
const NAMES = [
    { name: 'example.org', unicode_name: 'example.org', mailbox_count: 3, role: 'admin' },
    { name: 'xn--bcher-kva.example', unicode_name: 'bücher.example', mailbox_count: 1, role: 'admin' },
];
const ACTORS = [
    { key: '1', kind: 'user', name: 'Anna Admin' },
    { key: '7', kind: 'user', name: 'Bob Builder' },
    { key: '9', kind: 'deleted_user', name: 'Deleted user' },
    { key: 'cli', kind: 'cli', name: 'Command line' },
    { key: 'system', kind: 'system', name: 'System' },
];

function row(id: number, over: Partial<AuditFixtureRow> = {}): AuditFixtureRow {
    return {
        id,
        occurred_at: new Date(Date.now() - 5 * 60_000).toISOString(),
        actor_kind: 'user',
        actor_name: 'Bob Builder',
        action: 'mailbox.delete',
        object_type: 'mailbox',
        object_key: 'old@bücher.example',
        domain_name: 'xn--bcher-kva.example',
        changes: null,
        ...over,
    };
}

interface Serve {
    me?: unknown;
    rows?: AuditFixtureRow[];
    meta?: { page: number; per_page: number; total: number; last_page: number };
}

function serve({ me = ADMIN, rows = [row(41)], meta }: Serve = {}) {
    vi.mocked(apiFetch).mockImplementation(((path: string) => {
        if (path === '/me') return Promise.resolve(me);
        if (path === '/domains/names') return Promise.resolve({ data: NAMES });
        if (path === '/audit-log/actors') return Promise.resolve({ data: ACTORS });
        if (path === '/audit-log') return Promise.resolve({ data: rows, meta: meta ?? { page: 1, per_page: 10, total: rows.length, last_page: 1 } });
        return Promise.reject(new Error(`unexpected ${path}`));
    }) as typeof apiFetch);
}

/** The query of the latest `GET /audit-log`. */
function lastQuery(): ApiInit['query'] {
    return vi.mocked(apiFetch).mock.calls.filter(([path]) => path === '/audit-log').at(-1)?.[1]?.query;
}

function renderAt(path: string) {
    return renderRoutes(
        [
            {
                path: '/activity',
                element: (
                    <>
                        <ActivityPage />
                        <LocationProbe />
                    </>
                ),
            },
        ],
        path,
    );
}

const location = () => screen.getByTestId('location').textContent;

// The date filters become instants in the browser's time zone; 2026-10-25 is the day DST ends there.
inTimeZone('Europe/Helsinki');

beforeEach(() => {
    vi.mocked(apiFetch).mockReset();
    vi.mocked(useIsPhone).mockReturnValue(false);
    serve();
});

describe('ActivityPage', () => {
    it('shows time, person, sentence, object and the unicode domain', async () => {
        renderAt('/activity');
        const cell = await screen.findByText('deleted mailbox old@bücher.example');
        const tr = cell.closest('tr')!;
        const time = within(tr).getByText('5 min ago');
        expect(time.closest('time')).toHaveAttribute('title', expect.stringMatching(/2026|\d{4}/));
        // The person has a column from 1024 px; below it the sentence starts with them (CSS picks one).
        expect(within(tr).getAllByText('Bob Builder')).toHaveLength(2);
        expect(within(tr).getAllByRole('cell')[2]).toHaveTextContent('Bob Builder deleted mailbox old@bücher.example');
        expect(within(tr).getByText('old@bücher.example')).toBeInTheDocument();
        expect(within(tr).getByText('bücher.example')).toBeInTheDocument();
        for (const name of ['Time', 'Person', 'Action', 'Object', 'Domain']) {
            expect(screen.getByRole('columnheader', { name })).toBeInTheDocument();
        }
    });

    it('reads every filter from the URL and sends it to the API', async () => {
        renderAt('/activity?domain=xn--bcher-kva.example&actor=7&group=mailboxes&from=2026-10-25&to=2026-10-25&page=2&before=50');
        await screen.findByText('deleted mailbox old@bücher.example');
        expect(lastQuery()).toEqual({
            domain: 'xn--bcher-kva.example',
            actor: '7',
            action_group: 'mailboxes',
            from: '2026-10-25T00:00:00+03:00',
            to: '2026-10-26T00:00:00+02:00',
            before_id: 50,
            page: 2,
            per_page: 10,
        });
        expect(screen.getByRole('combobox', { name: 'Domain' })).toHaveValue('xn--bcher-kva.example');
        await waitFor(() => expect(screen.getByRole('combobox', { name: 'Person' })).toHaveValue('7'));
        expect(screen.getByRole('combobox', { name: 'Action' })).toHaveValue('mailboxes');
        expect(screen.getByLabelText('From')).toHaveValue('2026-10-25');
        expect(screen.getByLabelText('To')).toHaveValue('2026-10-25');
    });

    it('ignores malformed values in the URL', async () => {
        renderAt('/activity?actor=bob&group=everything&from=25.10.2026&before=x');
        await screen.findByText('deleted mailbox old@bücher.example');
        expect(lastQuery()).toEqual({ page: 1, per_page: 10 });
    });

    it('writes each filter to the URL, back to page 1 and without the paging anchor', async () => {
        const user = userEvent.setup();
        renderAt('/activity?page=3&before=50');
        await screen.findByText('deleted mailbox old@bücher.example');

        await user.selectOptions(screen.getByRole('combobox', { name: 'Domain' }), 'bücher.example');
        expect(location()).toBe('/activity?domain=xn--bcher-kva.example');

        await user.selectOptions(await screen.findByRole('combobox', { name: 'Person' }), 'Bob Builder');
        await user.selectOptions(screen.getByRole('combobox', { name: 'Action' }), 'Forwarding');
        await user.type(screen.getByLabelText('From'), '2026-10-01');
        await user.type(screen.getByLabelText('To'), '2026-10-25');
        expect(location()).toBe('/activity?domain=xn--bcher-kva.example&actor=7&group=forwarding&from=2026-10-01&to=2026-10-25');
        await waitFor(() =>
            expect(lastQuery()).toMatchObject({
                domain: 'xn--bcher-kva.example',
                actor: '7',
                action_group: 'forwarding',
                from: '2026-10-01T00:00:00+03:00',
                to: '2026-10-26T00:00:00+02:00',
                page: 1,
            }),
        );
        expect(lastQuery()).not.toHaveProperty('before_id');
    });

    it('restores the filters on the back button', async () => {
        const user = userEvent.setup();
        const { router } = renderAt('/activity?group=domains');
        await screen.findByText('deleted mailbox old@bücher.example');
        await user.selectOptions(screen.getByRole('combobox', { name: 'Action' }), 'Blocked addresses');
        expect(location()).toBe('/activity?group=blocked');

        await act(() => router.navigate(-1));
        expect(location()).toBe('/activity?group=domains');
        expect(screen.getByRole('combobox', { name: 'Action' })).toHaveValue('domains');
    });

    it('clears every filter', async () => {
        const user = userEvent.setup();
        renderAt('/activity?domain=example.org&actor=cli&group=access&from=2026-10-01&to=2026-10-02&page=2&before=9');
        await screen.findByText('deleted mailbox old@bücher.example');
        await user.click(screen.getByRole('button', { name: 'Clear filters' }));
        expect(location()).toBe('/activity');
        expect(screen.getByRole('combobox', { name: 'Domain' })).toHaveValue('');
        expect(screen.getByLabelText('From')).toHaveValue('');
    });

    it('offers no Clear filters without a filter', async () => {
        renderAt('/activity');
        await screen.findByText('deleted mailbox old@bücher.example');
        expect(screen.queryByRole('button', { name: 'Clear filters' })).not.toBeInTheDocument();
    });

    it("pins later pages to the first page's newest row", async () => {
        const user = userEvent.setup();
        serve({ rows: [row(41), row(40)], meta: { page: 1, per_page: 10, total: 25, last_page: 3 } });
        renderAt('/activity?group=mailboxes');
        await screen.findAllByText('deleted mailbox old@bücher.example');

        await user.click(screen.getByRole('button', { name: 'Next page' }));
        expect(location()).toBe('/activity?group=mailboxes&page=2&before=41');
        await waitFor(() => expect(lastQuery()).toMatchObject({ page: 2, before_id: 41, action_group: 'mailboxes' }));
    });

    it('drops the anchor when going back to page 1', async () => {
        const user = userEvent.setup();
        serve({ rows: [row(30)], meta: { page: 2, per_page: 10, total: 25, last_page: 3 } });
        renderAt('/activity?page=2&before=41');
        await screen.findByText('deleted mailbox old@bücher.example');
        await user.click(screen.getByRole('button', { name: 'Previous page' }));
        expect(location()).toBe('/activity');
    });

    it('lists people, deleted users, the command line and the system, marking you', async () => {
        renderAt('/activity');
        const select = await screen.findByRole('combobox', { name: 'Person' });
        await within(select).findByRole('option', { name: 'Bob Builder' });
        expect(within(select).getAllByRole('option').map((o) => o.textContent)).toEqual([
            'Everyone',
            'Anna Admin (you)',
            'Bob Builder',
            'Deleted user (#9)',
            'Command line',
            'System',
        ]);
    });

    it('offers every action group to a server admin', async () => {
        renderAt('/activity');
        const select = await screen.findByRole('combobox', { name: 'Action' });
        await within(select).findByRole('option', { name: 'Users' });
        expect(within(select).getAllByRole('option').map((o) => o.getAttribute('value'))).toEqual([
            '',
            'mailboxes',
            'forwarding',
            'domains',
            'blocked',
            'access',
            'account',
        ]);
    });

    it('hides the account group from someone who is not a server admin', async () => {
        serve({ me: MANAGER });
        const { client } = renderAt('/activity');
        await screen.findByText('deleted mailbox old@bücher.example');
        await waitFor(() => expect(client.getQueryData(queryKeys.me)).toEqual(MANAGER));
        const select = screen.getByRole('combobox', { name: 'Action' });
        expect(within(select).queryByRole('option', { name: 'Users' })).not.toBeInTheDocument();
        expect(within(select).getByRole('option', { name: 'Access' })).toBeInTheDocument();
    });

    it('says when nothing matches the filters, with Clear filters', async () => {
        serve({ rows: [] });
        renderAt('/activity?group=blocked');
        expect(await screen.findByText('No activity matches these filters')).toBeInTheDocument();
        expect(screen.getAllByRole('button', { name: 'Clear filters' }).length).toBeGreaterThan(0);
    });

    it('says when there is no activity at all', async () => {
        serve({ rows: [] });
        renderAt('/activity');
        expect(await screen.findByText('No activity yet')).toBeInTheDocument();
    });

    it('shows an error with Retry when the log fails to load', async () => {
        vi.mocked(apiFetch).mockImplementation(((path: string) => {
            if (path === '/audit-log') return Promise.reject(new Error('down'));
            if (path === '/audit-log/actors') return Promise.resolve({ data: [] });
            if (path === '/domains/names') return Promise.resolve({ data: NAMES });
            return Promise.resolve(ADMIN);
        }) as typeof apiFetch);
        renderAt('/activity');
        expect(await screen.findByRole('alert')).toHaveTextContent('The activity log could not be loaded.');
        expect(screen.getByRole('button', { name: 'Retry' })).toBeInTheDocument();
    });

    it('shows cards on a phone', async () => {
        vi.mocked(useIsPhone).mockReturnValue(true);
        renderAt('/activity');
        const text = await screen.findByText('deleted mailbox old@bücher.example', { exact: false });
        expect(text.closest('tr')).toBeNull();
        expect(screen.queryByRole('table')).not.toBeInTheDocument();
        const card = text.closest('article')!;
        expect(card).toHaveTextContent('Bob Builder deleted mailbox old@bücher.example');
        expect(card).toHaveTextContent('bücher.example');
        expect(within(card).getByText('5 min ago')).toBeInTheDocument();
    });
});
