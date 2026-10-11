import { useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { apiFetch, type ApiInit } from '../../api/client';
import { ApiProblem } from '../../api/problems';
import { queryKeys } from '../../api/types';
import { NAMES } from '../../test/domainServer';
import { renderRoutes } from '../../test/routing';
import { NewMailboxDrawer } from './NewMailboxDrawer';

vi.mock('../../api/client', () => ({ apiFetch: vi.fn() }));

const mocked = vi.mocked(apiFetch);

const STRONG = 'Tr0ub4dor-horse';
const WEAK = 'abcdefghijkl';

// The server's own strings (AddressCheckController); the address in them is the normalised, punycode one.
const CHECKS: Record<string, { status: string; message: (address: string) => string }> = {
    anna: { status: 'mailbox', message: (a) => `${a} is already a mailbox.` },
    jack: { status: 'forwarding', message: (a) => `${a} already forwards mail.` },
    new: { status: 'available', message: (a) => `${a} is available.` },
};

/** Answers the address check and the names list; `post` handles the create. */
function server(post: (path: string, init: ApiInit) => Promise<unknown> = (path, init) => Promise.resolve(created(init))) {
    mocked.mockImplementation(((path: string, init: ApiInit = {}) => {
        if ((init.method ?? 'GET') !== 'GET') return post(path, init);
        if (path === '/domains/names') return Promise.resolve({ data: NAMES });
        if (path.endsWith('/addresses/check')) {
            const local = String(init.query?.local ?? '');
            const domain = decodeURIComponent(path.split('/')[2] ?? '');
            const address = `${local}@${domain}`;
            const hit = CHECKS[local];
            if (!hit) {
                return Promise.resolve({ address, status: 'invalid', message: 'Only letters, numbers, dots, hyphens and underscores — no spaces.' });
            }
            return Promise.resolve({ address, status: hit.status, reason: null, message: hit.message(address) });
        }
        return Promise.reject(new Error(`unexpected ${path}`));
    }) as typeof apiFetch);
}

function created(init: ApiInit) {
    const body = init.json as { local: string; display_name: string | null; quota_bytes: number };
    return {
        local: body.local,
        address: `${body.local}@xn--bcher-kva.example`,
        display_name: body.display_name,
        quota_bytes: body.quota_bytes,
        quota_is_default: true,
        status: 'active',
        used_bytes: null,
        usage_checked_at: null,
        forwarding_count: 0,
    };
}

function posts(): [string, ApiInit][] {
    return mocked.mock.calls.filter(([, init]) => (init?.method ?? 'GET') !== 'GET').map(([p, init]) => [p, init ?? {}]);
}

/** A page with a button that opens the drawer, so a test can close and reopen it. */
function Harness({ domain }: { domain?: string }) {
    const [open, setOpen] = useState(true);
    return (
        <>
            <button type="button" onClick={() => setOpen(true)}>
                Open drawer
            </button>
            <NewMailboxDrawer open={open} onClose={() => setOpen(false)} domain={domain} />
        </>
    );
}

/** `null` opens it the dashboard way, with no domain. */
function renderDrawer(domain: string | null = 'xn--bcher-kva.example') {
    return renderRoutes([{ path: '/', element: <Harness domain={domain ?? undefined} /> }], '/');
}

function createButton() {
    return screen.getByRole('button', { name: 'Create mailbox' });
}

async function fill(user: ReturnType<typeof userEvent.setup>, local: string, password = STRONG) {
    await user.type(screen.getByLabelText('Address'), local);
    await user.type(screen.getByLabelText('Password'), password);
}

beforeEach(() => {
    mocked.mockReset();
    server();
});

describe('NewMailboxDrawer', () => {
    it('types the mailbox password into a masked text input that password managers do not offer to save', async () => {
        const user = userEvent.setup();
        renderDrawer();
        const input = screen.getByLabelText('Password');
        expect(input).toHaveAttribute('type', 'text');
        expect(input).toHaveAttribute('autocomplete', 'off');
        expect(input).toHaveAttribute('data-1p-ignore');
        expect(input).toHaveClass('[-webkit-text-security:disc]');
        await user.click(screen.getByRole('button', { name: 'Show' }));
        expect(input).not.toHaveClass('[-webkit-text-security:disc]');
    });

    it('shows the unicode domain next to the address field', async () => {
        renderDrawer();
        const dialog = screen.getByRole('dialog', { name: 'New mailbox' });
        expect(await within(dialog).findByText('@bücher.example')).toBeInTheDocument();
    });

    it('stays disabled while the check says mailbox, and enables when available with a score of 3', async () => {
        const user = userEvent.setup();
        renderDrawer();
        await fill(user, 'anna');
        expect(screen.getByText('Checking…')).toBeInTheDocument();
        const taken = await screen.findByText('anna@xn--bcher-kva.example is already a mailbox.');
        expect(taken).toHaveClass('text-danger');
        expect(screen.getByLabelText('Address')).toHaveAttribute('aria-invalid', 'true');
        expect(createButton()).toBeDisabled();

        await user.clear(screen.getByLabelText('Address'));
        await user.type(screen.getByLabelText('Address'), 'jack');
        expect(await screen.findByText('jack@xn--bcher-kva.example already forwards mail.')).toHaveClass('text-danger');
        expect(createButton()).toBeDisabled();

        await user.clear(screen.getByLabelText('Address'));
        await user.type(screen.getByLabelText('Address'), 'new');
        const ok = await screen.findByText('new@xn--bcher-kva.example is available.');
        expect(ok).toHaveClass('text-teal-dark');
        await waitFor(() => expect(createButton()).toBeEnabled());

        await user.clear(screen.getByLabelText('Password'));
        await user.type(screen.getByLabelText('Password'), WEAK);
        expect(createButton()).toBeDisabled();
    });

    it('debounces the check and does not run it for an empty address', async () => {
        const user = userEvent.setup();
        renderDrawer();
        await user.type(screen.getByLabelText('Address'), 'new');
        await screen.findByText('new@xn--bcher-kva.example is available.');
        const checks = mocked.mock.calls.filter(([p]) => p.endsWith('/addresses/check'));
        expect(checks).toEqual([['/domains/xn--bcher-kva.example/addresses/check', { query: { local: 'new' } }]]);
    });

    it('posts quota_bytes 5368709120 by default', async () => {
        const user = userEvent.setup();
        renderDrawer();
        await fill(user, 'new');
        await user.type(screen.getByLabelText('Display name (optional)'), '  New Person ');
        await waitFor(() => expect(createButton()).toBeEnabled());
        await user.click(createButton());
        await screen.findByRole('dialog', { name: 'Mailbox created' });
        expect(posts()).toEqual([
            [
                '/domains/xn--bcher-kva.example/mailboxes',
                {
                    method: 'POST',
                    json: { local: 'new', display_name: 'New Person', password: STRONG, quota_bytes: 5368709120 },
                },
            ],
        ]);
    });

    it('shows the password once, copies it, and forgets it after Done', async () => {
        const user = userEvent.setup();
        const { client } = renderDrawer();
        const writeText = vi.spyOn(navigator.clipboard, 'writeText');
        await fill(user, 'new');
        await waitFor(() => expect(createButton()).toBeEnabled());
        await user.click(createButton());

        const panel = await screen.findByRole('dialog', { name: 'Mailbox created' });
        expect(within(panel).getByText('Password — shown once')).toBeInTheDocument();
        expect(within(panel).getByText(STRONG)).toHaveClass('font-mono');
        expect(within(panel).getByText('new@bücher.example')).toBeInTheDocument();
        expect(screen.getByText('Created new@bücher.example.')).toBeInTheDocument();

        await user.click(within(panel).getByRole('button', { name: 'Copy' }));
        expect(writeText).toHaveBeenCalledWith(STRONG);
        expect(await within(panel).findByRole('button', { name: 'Copied' })).toBeInTheDocument();

        await user.click(within(panel).getByRole('button', { name: 'Done' }));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Open drawer' }));
        expect(await screen.findByRole('dialog', { name: 'New mailbox' })).toBeInTheDocument();

        expect(document.body.textContent).not.toContain(STRONG);
        expect(screen.getByLabelText('Password')).toHaveValue('');
        const holds = (value: unknown) => JSON.stringify(value ?? null).includes(STRONG);
        expect(client.getQueryCache().getAll().filter((q) => holds(q.state.data) || holds(q.queryKey))).toEqual([]);
        expect(
            client
                .getMutationCache()
                .getAll()
                .filter((m) => holds(m.state.variables) || holds(m.state.data)),
        ).toEqual([]);
    });

    it('forgets the shown-once password on pagehide, before the page can go into the back-forward cache', async () => {
        const user = userEvent.setup();
        renderDrawer();
        await fill(user, 'new');
        await waitFor(() => expect(createButton()).toBeEnabled());
        await user.click(createButton());
        await screen.findByText(STRONG);
        window.dispatchEvent(new Event('pagehide'));
        expect(document.body.textContent).not.toContain(STRONG);
    });

    it('forgets the password when closed from the created panel', async () => {
        const user = userEvent.setup();
        renderDrawer();
        await fill(user, 'new');
        await waitFor(() => expect(createButton()).toBeEnabled());
        await user.click(createButton());
        await screen.findByRole('dialog', { name: 'Mailbox created' });
        await user.click(screen.getByRole('button', { name: 'Close' }));
        await user.click(screen.getByRole('button', { name: 'Open drawer' }));
        await screen.findByRole('dialog', { name: 'New mailbox' });
        expect(document.body.textContent).not.toContain(STRONG);
    });

    it('Add another keeps the domain and clears every field', async () => {
        const user = userEvent.setup();
        renderDrawer(null);
        await screen.findByRole('option', { name: 'bücher.example' });
        await user.selectOptions(screen.getByRole('combobox', { name: 'Domain' }), 'bücher.example');
        await fill(user, 'new');
        await user.type(screen.getByLabelText('Display name (optional)'), 'New Person');
        await waitFor(() => expect(createButton()).toBeEnabled());
        await user.click(createButton());
        await user.click(await screen.findByRole('button', { name: 'Add another mailbox' }));

        const dialog = screen.getByRole('dialog', { name: 'New mailbox' });
        expect(within(dialog).getByRole('combobox', { name: 'Domain' })).toHaveValue('xn--bcher-kva.example');
        expect(within(dialog).getByLabelText('Address')).toHaveValue('');
        expect(within(dialog).getByLabelText('Display name (optional)')).toHaveValue('');
        expect(within(dialog).getByLabelText('Password')).toHaveValue('');
        expect(document.body.textContent).not.toContain(STRONG);
    });

    it('offers a domain select when opened without a domain and posts to the chosen one', async () => {
        const user = userEvent.setup();
        renderDrawer(null);
        await screen.findByRole('option', { name: 'zeta.example' });
        const select = screen.getByRole('combobox', { name: 'Domain' });
        expect(select).toHaveValue('example.org');
        expect(within(select).getAllByRole('option').map((o) => [o.textContent, (o as HTMLOptionElement).value])).toEqual([
            ['example.org', 'example.org'],
            ['bücher.example', 'xn--bcher-kva.example'],
            ['zeta.example', 'zeta.example'],
        ]);
        await user.selectOptions(select, 'zeta.example');
        expect(screen.getByText('@zeta.example')).toBeInTheDocument();
        await fill(user, 'new');
        await waitFor(() => expect(createButton()).toBeEnabled());
        await user.click(createButton());
        await screen.findByRole('dialog', { name: 'Mailbox created' });
        expect(posts()[0]?.[0]).toBe('/domains/zeta.example/mailboxes');
    });

    it('shows a 409 archiving as readable copy', async () => {
        const user = userEvent.setup();
        server(() => Promise.reject(new ApiProblem({ status: 409, title: 'Conflict', code: 'archiving' })));
        renderDrawer();
        await fill(user, 'new');
        await waitFor(() => expect(createButton()).toBeEnabled());
        await user.click(createButton());
        expect(
            await within(screen.getByRole('dialog')).findByText('This address is still being archived. Try again in a few minutes.'),
        ).toBeInTheDocument();
        expect(screen.getByRole('dialog', { name: 'New mailbox' })).toBeInTheDocument();
    });

    it('puts 422 field errors under the matching inputs', async () => {
        const user = userEvent.setup();
        server(() =>
            Promise.reject(
                new ApiProblem({
                    status: 422,
                    title: 'Invalid',
                    errors: { password: ['This password is too common.'], quota_bytes: ['The limit is too large.'] },
                }),
            ),
        );
        renderDrawer();
        await fill(user, 'new');
        await waitFor(() => expect(createButton()).toBeEnabled());
        await user.click(createButton());
        const pwError = await screen.findByText('This password is too common.');
        expect(screen.getByLabelText('Password')).toHaveAttribute('aria-describedby', pwError.id);
        expect(screen.getByLabelText('Password')).toHaveAttribute('aria-invalid', 'true');
        expect(screen.getByText('The limit is too large.')).toBeInTheDocument();
    });

    it('posts once on a double click and is busy while pending', async () => {
        const user = userEvent.setup();
        let release: (v: unknown) => void = () => {};
        server((_p, init) => new Promise((resolve) => (release = () => resolve(created(init)))));
        renderDrawer();
        await fill(user, 'new');
        await waitFor(() => expect(createButton()).toBeEnabled());
        const button = createButton();
        await user.dblClick(button);
        await waitFor(() => expect(button).toHaveAttribute('aria-busy', 'true'));
        expect(button).toBeDisabled();
        release(undefined);
        await screen.findByRole('dialog', { name: 'Mailbox created' });
        expect(posts()).toHaveLength(1);
    });

    it('cannot be closed while the create is pending, then shows the password', async () => {
        const user = userEvent.setup();
        let release: (v: unknown) => void = () => {};
        server((_p, init) => new Promise((resolve) => (release = () => resolve(created(init)))));
        renderDrawer();
        await fill(user, 'new');
        await waitFor(() => expect(createButton()).toBeEnabled());
        await user.click(createButton());
        await waitFor(() => expect(createButton()).toHaveAttribute('aria-busy', 'true'));

        const dialog = screen.getByRole('dialog', { name: 'New mailbox' });
        expect(within(dialog).getByRole('button', { name: 'Cancel' })).toBeDisabled();
        expect(within(dialog).getByRole('button', { name: 'Close' })).toBeDisabled();
        await user.keyboard('{Escape}');
        await user.click(within(dialog).getByRole('button', { name: 'Cancel' }));
        await user.click(screen.getByTestId('overlay-backdrop'));
        expect(screen.getByRole('dialog', { name: 'New mailbox' })).toBeInTheDocument();

        release(undefined);
        const panel = await screen.findByRole('dialog', { name: 'Mailbox created' });
        expect(within(panel).getByText(STRONG)).toBeInTheDocument();
    });

    it('posts once when the form is submitted twice before the first answer', async () => {
        const user = userEvent.setup();
        let release: (v: unknown) => void = () => {};
        server((_p, init) => new Promise((resolve) => (release = () => resolve(created(init)))));
        renderDrawer();
        await fill(user, 'new');
        await waitFor(() => expect(createButton()).toBeEnabled());
        const form = screen.getByRole('dialog').querySelector('form');
        if (!form) throw new Error('no form');
        fireEvent.submit(form);
        fireEvent.submit(form);
        release(undefined);
        await screen.findByRole('dialog', { name: 'Mailbox created' });
        expect(posts()).toHaveLength(1);
    });
});

/** Adds `GET /domains/{d}/settings` to the server set up by `server()`. */
function withSettings(settings: { max_mailboxes: number | null; max_quota_bytes_total: number | null; usage: { mailboxes: number; quota_bytes_allocated: number } }) {
    const base = mocked.getMockImplementation();
    mocked.mockImplementation(((path: string, init: ApiInit = {}) =>
        /^\/domains\/[^/]+\/settings$/.test(path) && (init.method ?? 'GET') === 'GET'
            ? Promise.resolve(settings)
            : base?.(path, init)) as typeof apiFetch);
}

describe('NewMailboxDrawer and the domain limits', () => {
    const GIB = 1073741824;

    it('shows how many mailboxes and how much storage are left when the domain is limited', async () => {
        withSettings({ max_mailboxes: 20, max_quota_bytes_total: 100 * GIB, usage: { mailboxes: 17, quota_bytes_allocated: 88 * GIB } });
        renderDrawer();
        const dialog = screen.getByRole('dialog', { name: 'New mailbox' });
        expect(await within(dialog).findByText('3 mailboxes left · 12 GB left')).toBeInTheDocument();
    });

    it('shows only the limit that is set, and nothing for an unlimited domain', async () => {
        withSettings({ max_mailboxes: 2, max_quota_bytes_total: null, usage: { mailboxes: 1, quota_bytes_allocated: 0 } });
        renderDrawer();
        expect(await screen.findByText('1 mailbox left')).toBeInTheDocument();
    });

    it('says nothing about limits when there are none', async () => {
        withSettings({ max_mailboxes: null, max_quota_bytes_total: null, usage: { mailboxes: 1, quota_bytes_allocated: 0 } });
        const { client } = renderDrawer();
        await waitFor(() => expect(client.getQueryData(queryKeys.domainSettings('xn--bcher-kva.example'))).toBeDefined());
        expect(screen.queryByText(/left/)).not.toBeInTheDocument();
    });

    it('puts a domain_limit refusal for the mailbox count on the address field', async () => {
        const user = userEvent.setup();
        server(() =>
            Promise.reject(
                new ApiProblem({
                    status: 422,
                    title: 'Unprocessable Content',
                    code: 'domain_limit',
                    errors: { local: ['bücher.example can have at most 20 mailboxes.'] },
                }),
            ),
        );
        withSettings({ max_mailboxes: 20, max_quota_bytes_total: null, usage: { mailboxes: 19, quota_bytes_allocated: 0 } });
        renderDrawer();
        await fill(user, 'new');
        await waitFor(() => expect(createButton()).toBeEnabled());
        await user.click(createButton());
        const error = await screen.findByText('bücher.example can have at most 20 mailboxes.');
        expect(screen.getByLabelText('Address')).toHaveAttribute('aria-invalid', 'true');
        expect(screen.getByLabelText('Address').getAttribute('aria-describedby')).toBe(error.id);
    });

    it('puts a domain_limit refusal for storage under the storage limit', async () => {
        const user = userEvent.setup();
        server(() =>
            Promise.reject(
                new ApiProblem({
                    status: 422,
                    title: 'Unprocessable Content',
                    code: 'domain_limit',
                    errors: { quota_bytes: ['bücher.example can allocate at most 10 GB of storage in total.'] },
                }),
            ),
        );
        renderDrawer();
        await fill(user, 'new');
        await waitFor(() => expect(createButton()).toBeEnabled());
        await user.click(createButton());
        expect(await screen.findByText('bücher.example can allocate at most 10 GB of storage in total.')).toHaveAttribute('role', 'alert');
        expect(screen.queryByText('The mailbox could not be created.')).not.toBeInTheDocument();
    });

    it('offers only the domains the user can write to when opened without a domain', async () => {
        mocked.mockReset();
        mocked.mockImplementation(((path: string) => {
            if (path === '/domains/names') {
                return Promise.resolve({
                    data: [
                        { ...NAMES[0], role: 'viewer' },
                        { ...NAMES[1], role: 'manager' },
                        { ...NAMES[2], role: 'viewer' },
                    ],
                });
            }
            return Promise.reject(new Error(`unexpected ${path}`));
        }) as typeof apiFetch);
        renderDrawer(null);
        const select = await screen.findByRole('combobox', { name: 'Domain' });
        await waitFor(() => expect(within(select).getAllByRole('option')).toHaveLength(1));
        expect(within(select).getByRole('option', { name: 'bücher.example' })).toBeInTheDocument();
        expect(await screen.findByText('@bücher.example')).toBeInTheDocument();
    });

    describe('setup instructions', () => {
        const OPTION = 'Send setup instructions to another address (without the password)';

        it('posts the mailbox first and then the welcome, with the recipient and the chosen language', async () => {
            const user = userEvent.setup();
            renderDrawer();
            await fill(user, 'new');
            expect(screen.queryByLabelText('Send to')).not.toBeInTheDocument();
            await user.click(screen.getByRole('checkbox', { name: OPTION }));
            expect(screen.getByLabelText('Email language')).toHaveValue('en');
            await user.type(screen.getByLabelText('Send to'), 'owner@gmail.com');
            await user.selectOptions(screen.getByLabelText('Email language'), 'fi');
            await waitFor(() => expect(createButton()).toBeEnabled());
            await user.click(createButton());
            const panel = await screen.findByRole('dialog', { name: 'Mailbox created' });
            expect(await within(panel).findByText('Setup instructions sent to owner@gmail.com.')).toBeInTheDocument();
            expect(posts().map(([path, init]) => [path, init.json])).toEqual([
                ['/domains/xn--bcher-kva.example/mailboxes', { local: 'new', display_name: null, password: STRONG, quota_bytes: 5368709120 }],
                ['/domains/xn--bcher-kva.example/mailboxes/new/welcome', { to: 'owner@gmail.com', locale: 'fi' }],
            ]);
            expect(within(panel).getByText(STRONG)).toBeInTheDocument();
        });

        it('keeps the created state and the password and warns when the welcome fails', async () => {
            const user = userEvent.setup();
            server((path, init) =>
                path.endsWith('/welcome')
                    ? Promise.reject(
                          new ApiProblem({ status: 422, title: 'Unprocessable', code: 'validation_failed', errors: { to: ['Enter a valid email address.'] } }),
                      )
                    : Promise.resolve(created(init)),
            );
            renderDrawer();
            await fill(user, 'new');
            await user.click(screen.getByRole('checkbox', { name: OPTION }));
            await user.type(screen.getByLabelText('Send to'), 'owner@gmail');
            await waitFor(() => expect(createButton()).toBeEnabled());
            await user.click(createButton());
            const panel = await screen.findByRole('dialog', { name: 'Mailbox created' });
            const warning = await within(panel).findByRole('alert');
            expect(warning).toHaveTextContent('The mailbox was created, but the setup instructions were not sent');
            expect(warning).toHaveTextContent('Enter a valid email address.');
            expect(within(panel).getByText(STRONG)).toBeInTheDocument();
        });

        it('does not post a welcome when the box is unchecked, and keeps Create off until a recipient is typed', async () => {
            const user = userEvent.setup();
            renderDrawer();
            await fill(user, 'new');
            await waitFor(() => expect(createButton()).toBeEnabled());
            await user.click(screen.getByRole('checkbox', { name: OPTION }));
            expect(createButton()).toBeDisabled();
            await user.click(screen.getByRole('checkbox', { name: OPTION }));
            await user.click(createButton());
            await screen.findByRole('dialog', { name: 'Mailbox created' });
            expect(posts()).toHaveLength(1);
        });
    });
});
