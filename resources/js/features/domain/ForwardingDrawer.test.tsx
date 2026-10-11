import { useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { apiFetch, type ApiInit } from '../../api/client';
import { ApiProblem } from '../../api/problems';
import { NAMES } from '../../test/domainServer';
import { renderRoutes } from '../../test/routing';
import { ForwardingDrawer, type ForwardingRow } from './ForwardingDrawer';

vi.mock('../../api/client', () => ({ apiFetch: vi.fn() }));

const mocked = vi.mocked(apiFetch);
const D = 'xn--bcher-kva.example';

// The server's own strings (AddressCheckController).
const CHECKS: Record<string, { status: string; message: (a: string) => string }> = {
    anna: { status: 'mailbox', message: (a) => `${a} is already a mailbox.` },
    bob: { status: 'mailbox', message: (a) => `${a} is already a mailbox.` },
    jack: { status: 'forwarding', message: (a) => `${a} already forwards mail.` },
    spam: { status: 'blocked', message: (a) => `${a} is blocked — reason: “abuse”.` },
    new: { status: 'available', message: (a) => `${a} is available.` },
};

function row(local: string, targets: [string, 'mailbox' | 'server' | 'external'][], over: Partial<ForwardingRow> = {}): ForwardingRow {
    return {
        local,
        source: `${local}@${D}`,
        source_is_mailbox: false,
        catch_all: false,
        keep_copy: false,
        targets: targets.map(([address, kind]) => ({ address, kind })),
        ...over,
    };
}

interface State {
    mailboxes: string[];
    forwarding: ForwardingRow[];
    /** The list's `meta.total`, when the search matched more rows than the page holds. */
    forwardingTotal?: number;
    put: (path: string, init: ApiInit) => Promise<unknown>;
}

let state: State;

function server() {
    mocked.mockImplementation(((path: string, init: ApiInit = {}) => {
        if ((init.method ?? 'GET') !== 'GET') return state.put(path, init);
        if (path === '/domains/names') return Promise.resolve({ data: NAMES });
        if (path.endsWith('/addresses/check')) {
            const local = String(init.query?.local ?? '');
            const domain = decodeURIComponent(path.split('/')[2] ?? '');
            const address = `${local}@${domain}`;
            const hit = CHECKS[local];
            if (!hit) return Promise.resolve({ address, status: 'invalid', message: 'Only letters, numbers, dots, hyphens and underscores — no spaces.' });
            return Promise.resolve({ address, status: hit.status, reason: null, message: hit.message(address) });
        }
        if (path.endsWith('/mailboxes')) {
            const q = String(init.query?.q ?? '');
            const data = state.mailboxes.filter((l) => l.includes(q)).map((local) => ({ local }));
            return Promise.resolve({ data, meta: { page: 1, per_page: 200, total: data.length, last_page: 1 } });
        }
        if (path.endsWith('/forwarding')) {
            const q = String(init.query?.q ?? '');
            const data = state.forwarding.filter((r) => r.source.includes(q));
            const total = state.forwardingTotal ?? data.length;
            return Promise.resolve({ data, meta: { page: 1, per_page: 200, total, last_page: Math.ceil(total / 200) || 1 } });
        }
        return Promise.reject(new Error(`unexpected ${path}`));
    }) as typeof apiFetch);
}

function echo(path: string, init: ApiInit) {
    const body = init.json as { targets: string[]; keep_copy?: boolean };
    const local = decodeURIComponent(path.split('/').pop() ?? '');
    return Promise.resolve(
        row(
            local,
            body.targets.map((t) => [t, 'external']),
            { keep_copy: body.keep_copy ?? false },
        ),
    );
}

function puts(): [string, ApiInit][] {
    return mocked.mock.calls.filter(([, init]) => (init?.method ?? 'GET') !== 'GET').map(([p, init]) => [p, init ?? {}]);
}

function Harness(props: { domain?: string; editing?: ForwardingRow; initialTargets?: string[]; catchAll?: boolean }) {
    const [open, setOpen] = useState(true);
    return (
        <>
            <output data-testid="open">{open ? 'open' : 'closed'}</output>
            <ForwardingDrawer open={open} onClose={() => setOpen(false)} {...props} />
        </>
    );
}

function renderDrawer(props: { domain?: string | null; editing?: ForwardingRow; initialTargets?: string[]; catchAll?: boolean } = {}) {
    const { domain = D, ...rest } = props;
    return renderRoutes([{ path: '/', element: <Harness domain={domain ?? undefined} {...rest} /> }], '/');
}

const source = () => screen.getByLabelText('Address that receives the mail');
const deliverTo = () => screen.getByLabelText('Deliver to');
const addButton = () => screen.getByRole('button', { name: 'Add forwarding address' });

beforeEach(() => {
    mocked.mockReset();
    state = { mailboxes: ['anna', 'bob', 'hr'], forwarding: [], put: echo };
    server();
});

describe('ForwardingDrawer', () => {
    it('adds a new group from an available source, without keep_copy, accepting a plus target', async () => {
        const user = userEvent.setup();
        renderDrawer();
        expect(screen.getByRole('dialog', { name: 'New forwarding address' })).toBeInTheDocument();
        await user.type(source(), 'new');
        expect(await screen.findByText('new@xn--bcher-kva.example is available.')).toBeInTheDocument();
        expect(screen.queryByRole('switch')).not.toBeInTheDocument();

        await user.type(deliverTo(), 'Me+Tag@Gmail.com{Enter}');
        expect(screen.getByText('me+tag@gmail.com')).toBeInTheDocument();
        expect(screen.getByText('Mail forwarded to a wrong external address is lost. Double-check it.')).toBeInTheDocument();

        await user.click(addButton());
        await waitFor(() => expect(screen.getByTestId('open')).toHaveTextContent('closed'));
        expect(puts()).toEqual([[`/domains/${D}/forwarding/new`, { method: 'PUT', json: { targets: ['me+tag@gmail.com'] } }]]);
        expect(await screen.findByText('new@bücher.example now delivers to 1 place.')).toBeInTheDocument();
    });

    it('needs at least one destination', async () => {
        const user = userEvent.setup();
        renderDrawer();
        await user.type(source(), 'new');
        await screen.findByText('new@xn--bcher-kva.example is available.');
        expect(addButton()).toBeDisabled();
    });

    it('refuses a blocked source with the server message', async () => {
        const user = userEvent.setup();
        renderDrawer({ initialTargets: ['x@gmail.com'] });
        await user.type(source(), 'spam');
        expect(await screen.findByText('spam@xn--bcher-kva.example is blocked — reason: “abuse”.')).toBeInTheDocument();
        expect(source()).toHaveAttribute('aria-invalid', 'true');
        expect(addButton()).toBeDisabled();
    });

    it('shows the keep-a-copy switch, on, for a mailbox source and sends keep_copy true', async () => {
        const user = userEvent.setup();
        renderDrawer({ initialTargets: ['x@gmail.com'] });
        await user.type(source(), 'anna');
        const keep = await screen.findByRole('switch', { name: 'Keep a copy in the mailbox' });
        expect(keep).toHaveAttribute('aria-checked', 'true');
        await user.click(addButton());
        await waitFor(() => expect(puts()).toHaveLength(1));
        expect(puts()[0]?.[1].json).toEqual({ targets: ['x@gmail.com'], keep_copy: true });
    });

    it('sends redirect_mailbox only after the inline confirm when keep-copy is turned off', async () => {
        const user = userEvent.setup();
        renderDrawer({ initialTargets: ['x@gmail.com'] });
        await user.type(source(), 'anna');
        await user.click(await screen.findByRole('switch', { name: 'Keep a copy in the mailbox' }));

        expect(screen.getByText('Mail to anna@bücher.example will only be forwarded, not kept.')).toBeInTheDocument();
        expect(addButton()).toBeDisabled();

        // Cancel turns the copy back on.
        await user.click(screen.getByRole('button', { name: 'Cancel turning off' }));
        expect(screen.getByRole('switch', { name: 'Keep a copy in the mailbox' })).toHaveAttribute('aria-checked', 'true');
        expect(screen.queryByText('Mail to anna@bücher.example will only be forwarded, not kept.')).not.toBeInTheDocument();

        await user.click(screen.getByRole('switch', { name: 'Keep a copy in the mailbox' }));
        await user.click(screen.getByRole('button', { name: 'Confirm' }));
        expect(screen.getByRole('switch', { name: 'Keep a copy in the mailbox' })).toHaveAttribute('aria-checked', 'false');
        await user.click(addButton());
        await waitFor(() => expect(puts()).toHaveLength(1));
        expect(puts()[0]?.[1].json).toEqual({ targets: ['x@gmail.com'], keep_copy: false, redirect_mailbox: true });
    });

    it('treats a mailbox that already forwards like an existing group and offers to edit it', async () => {
        state.forwarding = [row('bob', [['bob@gmail.com', 'external']], { source_is_mailbox: true, keep_copy: true })];
        const user = userEvent.setup();
        renderDrawer({ initialTargets: ['anna@xn--bcher-kva.example'] });
        await user.type(source(), 'bob');
        expect(await screen.findByText('bob@xn--bcher-kva.example already forwards mail.')).toBeInTheDocument();
        expect(addButton()).toBeDisabled();
        expect(mocked).toHaveBeenCalledWith(`/domains/${D}/forwarding`, { query: { q: 'bob@xn--bcher-kva.example', limit: 200 } });

        await user.click(screen.getByRole('button', { name: 'Edit it instead' }));
        expect(await screen.findByRole('dialog', { name: 'Edit forwarding' })).toBeInTheDocument();
        expect(source()).toHaveAttribute('readonly');
        expect(screen.getByText('bob@gmail.com')).toBeInTheDocument();
        expect(screen.getByText('anna@xn--bcher-kva.example')).toBeInTheDocument();
        expect(screen.getByRole('switch', { name: 'Keep a copy in the mailbox' })).toHaveAttribute('aria-checked', 'true');

        await user.click(screen.getByRole('button', { name: 'Save destinations' }));
        await waitFor(() => expect(puts()).toHaveLength(1));
        expect(puts()[0]).toEqual([
            `/domains/${D}/forwarding/bob`,
            { method: 'PUT', json: { targets: ['bob@gmail.com', 'anna@xn--bcher-kva.example'], keep_copy: true } },
        ]);
    });

    it('fails closed when the lookup matched more rows than it returned and the source is not among them', async () => {
        state.forwarding = [row('annabel', [['x@gmail.com', 'external']])];
        state.forwardingTotal = 250;
        const user = userEvent.setup();
        renderDrawer({ initialTargets: ['x@gmail.com'] });
        await user.type(source(), 'anna');
        expect(await screen.findByText('The address could not be checked.')).toBeInTheDocument();
        expect(source()).toHaveAttribute('aria-invalid', 'true');
        expect(screen.queryByRole('switch')).not.toBeInTheDocument();
        expect(addButton()).toBeDisabled();
    });

    it('asks again for forward-only after the domain changes', async () => {
        const user = userEvent.setup();
        renderDrawer({ domain: null, initialTargets: ['x@gmail.com'] });
        const select = await screen.findByRole('combobox', { name: 'Domain' });
        await within(select).findByRole('option', { name: 'bücher.example' });
        await user.type(source(), 'anna');
        await user.click(await screen.findByRole('switch', { name: 'Keep a copy in the mailbox' }));
        await user.click(screen.getByRole('button', { name: 'Confirm' }));
        expect(screen.getByRole('switch', { name: 'Keep a copy in the mailbox' })).toHaveAttribute('aria-checked', 'false');

        await user.selectOptions(select, D);
        expect(screen.queryByText('Mail to anna@bücher.example will only be forwarded, not kept.')).not.toBeInTheDocument();
        await waitFor(() => expect(screen.getByRole('switch', { name: 'Keep a copy in the mailbox' })).toHaveAttribute('aria-checked', 'true'));
        await waitFor(() => expect(addButton()).toBeEnabled());
        await user.click(addButton());
        await waitFor(() => expect(puts()).toHaveLength(1));
        expect(puts()[0]).toEqual([`/domains/${D}/forwarding/anna`, { method: 'PUT', json: { targets: ['x@gmail.com'], keep_copy: true } }]);
    });

    it('opens an existing forwarding source in edit mode from the check', async () => {
        state.forwarding = [row('jack', [['jack@other.example', 'server']])];
        const user = userEvent.setup();
        renderDrawer();
        await user.type(source(), 'jack');
        expect(await screen.findByText('jack@xn--bcher-kva.example already forwards mail.')).toBeInTheDocument();
        await user.click(await screen.findByRole('button', { name: 'Edit it instead' }));
        expect(await screen.findByText('jack@other.example')).toBeInTheDocument();
        expect(screen.queryByRole('switch')).not.toBeInTheDocument();
    });

    it('edits an existing group with the source read-only and the stored keep_copy', async () => {
        const user = userEvent.setup();
        const editing = row('anna', [['out@gmail.com', 'external']], { source_is_mailbox: true, keep_copy: false });
        renderDrawer({ editing });
        expect(screen.getByRole('dialog', { name: 'Edit forwarding' })).toBeInTheDocument();
        expect(source()).toHaveValue('anna');
        expect(source()).toHaveAttribute('readonly');
        // Already forward-only: no confirm needed to keep it that way.
        expect(screen.getByRole('switch', { name: 'Keep a copy in the mailbox' })).toHaveAttribute('aria-checked', 'false');
        await user.type(deliverTo(), 'two@gmail.com{Enter}');
        await user.click(screen.getByRole('button', { name: 'Save destinations' }));
        await waitFor(() => expect(puts()).toHaveLength(1));
        expect(puts()[0]?.[1].json).toEqual({ targets: ['out@gmail.com', 'two@gmail.com'], keep_copy: false, redirect_mailbox: true });
        expect(mocked.mock.calls.some(([p]) => p.endsWith('/addresses/check'))).toBe(false);
    });

    it('starts from the given destinations and refuses delivering to itself', async () => {
        const user = userEvent.setup();
        renderDrawer({ initialTargets: ['anna@xn--bcher-kva.example'] });
        expect(screen.getByText('anna@xn--bcher-kva.example')).toBeInTheDocument();
        await user.type(source(), 'new');
        await user.type(deliverTo(), 'new@xn--bcher-kva.example{Enter}');
        expect(screen.getByRole('alert')).toHaveTextContent('An address can’t deliver to itself.');
    });

    it('hides the info@ suggestion when no info mailbox exists and adds hr@ on click', async () => {
        state.mailboxes = ['anna', 'hrdept', 'hr'];
        const user = userEvent.setup();
        renderDrawer();
        const hr = await screen.findByRole('button', { name: 'hr@bücher.example' });
        expect(screen.queryByRole('button', { name: 'info@bücher.example' })).not.toBeInTheDocument();
        await user.click(hr);
        expect(screen.getByText('hr@xn--bcher-kva.example')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'hr@bücher.example' })).not.toBeInTheDocument();
        expect(mocked).toHaveBeenCalledWith(`/domains/${D}/mailboxes`, { query: { q: 'info', limit: 200 } });
    });

    it('shows the info@ suggestion only for an exact info mailbox', async () => {
        state.mailboxes = ['info'];
        renderDrawer();
        expect(await screen.findByRole('button', { name: 'info@bücher.example' })).toBeInTheDocument();
    });

    it('puts 422 target errors under Deliver to', async () => {
        state.put = () =>
            Promise.reject(
                new ApiProblem({ status: 422, title: 'Unprocessable', errors: { 'targets.0': ['“x@y.zz” is not a complete address.'] } }),
            );
        const user = userEvent.setup();
        renderDrawer({ initialTargets: ['x@y.zz'] });
        await user.type(source(), 'new');
        await screen.findByText('new@xn--bcher-kva.example is available.');
        await user.click(addButton());
        expect(await screen.findByText('“x@y.zz” is not a complete address.')).toBeInTheDocument();
        expect(deliverTo()).toHaveAttribute('aria-invalid', 'true');
        expect(screen.getByTestId('open')).toHaveTextContent('open');
    });

    it('shows a 409 in words and a redirect_not_confirmed next to the keep-copy setting', async () => {
        state.put = () => Promise.reject(new ApiProblem({ status: 409, title: 'Conflict', code: 'busy' }));
        const user = userEvent.setup();
        renderDrawer({ initialTargets: ['x@gmail.com'] });
        await user.type(source(), 'new');
        await screen.findByText('new@xn--bcher-kva.example is available.');
        await user.click(addButton());
        expect(await screen.findByText('Someone else is changing this address. Try again.')).toBeInTheDocument();

        state.put = () =>
            Promise.reject(
                new ApiProblem({
                    status: 422,
                    title: 'Unprocessable',
                    code: 'redirect_not_confirmed',
                    errors: { redirect_mailbox: ['new@xn--bcher-kva.example is a mailbox. Confirm that its mail should be forwarded only.'] },
                }),
            );
        await user.click(addButton());
        expect(
            await screen.findByText('new@xn--bcher-kva.example is a mailbox. Confirm that its mail should be forwarded only.'),
        ).toBeInTheDocument();
    });

    it('locks the drawer while saving', async () => {
        let resolve: (v: unknown) => void = () => {};
        state.put = () => new Promise((r) => (resolve = r));
        const user = userEvent.setup();
        renderDrawer({ initialTargets: ['x@gmail.com'] });
        await user.type(source(), 'new');
        await screen.findByText('new@xn--bcher-kva.example is available.');
        await user.dblClick(addButton());
        expect(screen.getByRole('button', { name: 'Close' })).toBeDisabled();
        await user.keyboard('{Escape}');
        expect(screen.getByTestId('open')).toHaveTextContent('open');
        resolve(row('new', [['x@gmail.com', 'external']]));
        await waitFor(() => expect(screen.getByTestId('open')).toHaveTextContent('closed'));
        expect(puts()).toHaveLength(1);
    });

    it('asks for the domain when opened without one', async () => {
        const user = userEvent.setup();
        renderDrawer({ domain: null });
        const select = await screen.findByRole('combobox', { name: 'Domain' });
        await within(select).findByRole('option', { name: 'bücher.example' });
        await user.selectOptions(select, D);
        await user.type(source(), 'new');
        expect(await screen.findByText('new@xn--bcher-kva.example is available.')).toBeInTheDocument();
    });
});

describe('ForwardingDrawer for a catch-all', () => {
    const catchAll = row('', [['anna@xn--bcher-kva.example', 'mailbox']], { source: `@${D}`, catch_all: true });

    it('offers to edit the catch-all a domain already has instead of replacing it', async () => {
        const user = userEvent.setup();
        state.forwarding = [catchAll, row('sales', [['bob@xn--bcher-kva.example', 'mailbox']])];
        renderDrawer({ catchAll: true });
        expect(screen.getByRole('dialog', { name: 'Catch-all forwarding' })).toBeInTheDocument();
        expect(await screen.findByText('bücher.example already has catch-all forwarding.')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Set catch-all' })).toBeDisabled();
        await user.click(screen.getByRole('button', { name: 'Edit it instead' }));
        expect(screen.getByText('anna@xn--bcher-kva.example')).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Save destinations' }));
        await waitFor(() => expect(puts()).toEqual([[`/domains/${D}/forwarding/@`, { method: 'PUT', json: { targets: ['anna@xn--bcher-kva.example'] } }]]));
    });

    it('fails closed when the lookup cannot say whether there is one', async () => {
        const user = userEvent.setup();
        state.forwarding = [row('sales', [['bob@xn--bcher-kva.example', 'mailbox']])];
        state.forwardingTotal = 500;
        renderDrawer({ catchAll: true });
        await user.type(deliverTo(), 'anna@xn--bcher-kva.example{Enter}');
        expect(await screen.findByText('The address could not be checked.')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Set catch-all' })).toBeDisabled();
    });
});
