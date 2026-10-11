import { useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { apiFetch, type ApiInit } from '../../api/client';
import { ApiProblem } from '../../api/problems';
import { mailbox, NAMES } from '../../test/domainServer';
import { renderRoutes } from '../../test/routing';
import { EditMailboxDrawer } from './EditMailboxDrawer';

vi.mock('../../api/client', () => ({ apiFetch: vi.fn() }));

const mocked = vi.mocked(apiFetch);
const GIB = 1073741824;
const SECRET = 'Kq7-mZ2_pXw9!rT4';

interface State {
    mailboxes: Record<string, Record<string, unknown>>;
    /** The live usage answer, or a problem to reject with. */
    usage: { used_bytes: number; limit_bytes: number | null; checked_at: string } | ApiProblem;
    forwarding: unknown[];
    role?: 'admin' | 'manager' | 'viewer';
}

let state: State;

function forwardingRow(local: string, targets: string[]) {
    return {
        local,
        source: `${local}@xn--bcher-kva.example`,
        source_is_mailbox: false,
        catch_all: false,
        keep_copy: false,
        targets: targets.map((address) => ({ address, kind: 'mailbox' })),
    };
}

function server() {
    mocked.mockImplementation(((path: string, init: ApiInit = {}) => {
        const method = init.method ?? 'GET';
        const m = /^\/domains\/[^/]+\/mailboxes\/([^/]+)(?:\/(\w+))?$/.exec(path);
        const local = m ? decodeURIComponent(m[1] ?? '') : '';
        const action = m?.[2];
        const box = state.mailboxes[local];
        if (method === 'GET') {
            if (path === '/domains/names') return Promise.resolve({ data: NAMES.map((n) => ({ ...n, role: state.role ?? n.role })) });
            if (path.endsWith('/forwarding')) {
                return Promise.resolve({ data: state.forwarding, meta: { page: 1, per_page: 200, total: state.forwarding.length, last_page: 1 } });
            }
            if (action === 'usage') return state.usage instanceof ApiProblem ? Promise.reject(state.usage) : Promise.resolve(state.usage);
            if (box && action === undefined) return Promise.resolve(box);
            return Promise.reject(new ApiProblem({ status: 404, title: 'Not Found' }));
        }
        if (!box) return Promise.reject(new ApiProblem({ status: 404, title: 'Not Found' }));
        if (method === 'PATCH') {
            const body = init.json as { quota_bytes?: number; display_name?: string | null };
            state.mailboxes[local] = { ...box, ...body };
            return Promise.resolve(state.mailboxes[local]);
        }
        if (action === 'welcome') return Promise.resolve(undefined);
        if (action === 'password') return Promise.resolve({ password: SECRET });
        if (action === 'disable' || action === 'enable') {
            state.mailboxes[local] = { ...box, status: action === 'disable' ? 'disabled' : 'active' };
            return Promise.resolve(undefined);
        }
        if (method === 'DELETE') {
            delete state.mailboxes[local];
            return Promise.resolve({ archived: true });
        }
        return Promise.reject(new Error(`unexpected ${method} ${path}`));
    }) as typeof apiFetch);
}

function writes(): [string, ApiInit][] {
    return mocked.mock.calls.filter(([, init]) => (init?.method ?? 'GET') !== 'GET').map(([p, init]) => [p, init ?? {}]);
}

/** A page with buttons that open the drawer for a mailbox, so a test can close, reopen and switch. */
function Harness({ initial, focus }: { initial: string; focus?: 'password' }) {
    const [local, setLocal] = useState<string | null>(initial);
    return (
        <>
            <button type="button" onClick={() => setLocal('anna')}>
                Open anna
            </button>
            <button type="button" onClick={() => setLocal('bob')}>
                Open bob
            </button>
            <EditMailboxDrawer
                open={local !== null}
                onClose={() => setLocal(null)}
                domain="xn--bcher-kva.example"
                local={local ?? ''}
                focus={focus}
            />
        </>
    );
}

function renderDrawer(initial = 'anna', focus?: 'password') {
    return renderRoutes([{ path: '/', element: <Harness initial={initial} focus={focus} /> }], '/');
}

async function drawer(name = 'anna@bücher.example') {
    return screen.findByRole('dialog', { name });
}

beforeEach(() => {
    mocked.mockReset();
    state = {
        mailboxes: {
            anna: mailbox('anna', { address: 'anna@xn--bcher-kva.example', display_name: 'Anna Admin', used_bytes: GIB }),
            bob: mailbox('bob', { address: 'bob@xn--bcher-kva.example', status: 'disabled' }),
        },
        usage: { used_bytes: 3 * GIB, limit_bytes: 5 * GIB, checked_at: new Date().toISOString() },
        forwarding: [],
    };
    server();
});

describe('EditMailboxDrawer', () => {
    it('shows the address, display name and the live usage', async () => {
        renderDrawer();
        const panel = await drawer();
        expect(within(panel).getByText('Anna Admin · Domains / bücher.example / Mailboxes')).toBeInTheDocument();
        expect(await within(panel).findByText('3 GB of 5 GB used · checked just now')).toBeInTheDocument();
        expect(mocked).toHaveBeenCalledWith('/domains/xn--bcher-kva.example/mailboxes/anna/usage');
    });

    it('says storage use is unavailable on a 503 and falls back to the list figure', async () => {
        state.usage = new ApiProblem({ status: 503, title: 'Service Unavailable', code: 'usage_unavailable' });
        renderDrawer();
        const panel = await drawer();
        expect(await within(panel).findByText('Storage use is unavailable right now.')).toBeInTheDocument();
        expect(within(panel).getByText(/^1 GB of 5 GB used · checked /)).toBeInTheDocument();
    });

    it('disables Save limit below the space used, and saves a larger limit', async () => {
        const user = userEvent.setup();
        renderDrawer();
        const panel = await drawer();
        await within(panel).findByText('3 GB of 5 GB used · checked just now');
        const save = within(panel).getByRole('button', { name: 'Save limit' });
        const input = within(panel).getByLabelText('Storage limit');
        expect(save).toBeDisabled();

        await user.clear(input);
        await user.type(input, '2');
        expect(within(panel).getByText('Can\'t be lower than the space already used (3 GB).')).toBeInTheDocument();
        expect(save).toBeDisabled();

        await user.clear(input);
        await user.type(input, '10');
        expect(save).toBeEnabled();
        await user.click(save);
        expect(await screen.findByText('Limit saved.')).toBeInTheDocument();
        expect(writes()).toEqual([['/domains/xn--bcher-kva.example/mailboxes/anna', { method: 'PATCH', json: { quota_bytes: 10 * GIB } }]]);
        await waitFor(() => expect(save).toBeDisabled());
    });

    it('keeps Save limit disabled while the live usage is loading, then checks the typed limit against it', async () => {
        const user = userEvent.setup();
        let answer: (value: unknown) => void = () => {};
        const pending = new Promise((resolve) => {
            answer = resolve;
        });
        const base = mocked.getMockImplementation();
        mocked.mockImplementation(((path: string, init?: ApiInit) =>
            path.endsWith('/usage') ? pending : base?.(path, init)) as typeof apiFetch);
        renderDrawer();
        const panel = await drawer();
        const save = within(panel).getByRole('button', { name: 'Save limit' });
        const input = within(panel).getByLabelText('Storage limit');

        // The list figure (1 GB) is all there is until the live answer arrives.
        await user.clear(input);
        await user.type(input, '2');
        expect(save).toBeDisabled();

        answer({ used_bytes: 3 * GIB, limit_bytes: 5 * GIB, checked_at: new Date().toISOString() });
        expect(await within(panel).findByText('Can\'t be lower than the space already used (3 GB).')).toBeInTheDocument();
        expect(save).toBeDisabled();
    });

    it('saves the display name with its own button', async () => {
        const user = userEvent.setup();
        renderDrawer();
        const panel = await drawer();
        const name = within(panel).getByLabelText('Display name');
        const save = within(panel).getByRole('button', { name: 'Save name' });
        expect(name).toHaveValue('Anna Admin');
        expect(save).toBeDisabled();

        await user.clear(name);
        await user.type(name, '  Anna A. ');
        await user.click(save);
        expect(await screen.findByText('Display name saved.')).toBeInTheDocument();
        await user.clear(name);
        await user.click(save);
        expect(writes()).toEqual([
            ['/domains/xn--bcher-kva.example/mailboxes/anna', { method: 'PATCH', json: { display_name: 'Anna A.' } }],
            ['/domains/xn--bcher-kva.example/mailboxes/anna', { method: 'PATCH', json: { display_name: null } }],
        ]);
    });

    it('shows a generated password once, copies it, and forgets it on close', async () => {
        const user = userEvent.setup();
        const { client } = renderDrawer();
        const writeText = vi.spyOn(navigator.clipboard, 'writeText');
        const panel = await drawer();
        await user.click(within(panel).getByRole('button', { name: 'Generate a new password' }));

        expect(await within(panel).findByText(SECRET)).toHaveClass('font-mono');
        expect(within(panel).getByText('New password — shown once')).toBeInTheDocument();
        expect(within(panel).queryByRole('button', { name: 'Generate a new password' })).not.toBeInTheDocument();
        expect(writes()).toEqual([['/domains/xn--bcher-kva.example/mailboxes/anna/password', { method: 'POST', json: { generate: true } }]]);

        await user.click(within(panel).getByRole('button', { name: 'Copy' }));
        expect(writeText).toHaveBeenCalledWith(SECRET);
        expect(await within(panel).findByRole('button', { name: 'Copied' })).toBeInTheDocument();

        await user.click(within(panel).getByRole('button', { name: 'Close' }));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(document.body.textContent).not.toContain(SECRET);

        await user.click(screen.getByRole('button', { name: 'Open anna' }));
        const again = await drawer();
        expect(within(again).getByRole('button', { name: 'Generate a new password' })).toBeInTheDocument();
        expect(document.body.textContent).not.toContain(SECRET);

        const holds = (value: unknown) => JSON.stringify(value ?? null).includes(SECRET);
        expect(client.getQueryCache().getAll().filter((q) => holds(q.state.data) || holds(q.queryKey))).toEqual([]);
        expect(
            client
                .getMutationCache()
                .getAll()
                .filter((m) => holds(m.state.variables) || holds(m.state.data)),
        ).toEqual([]);
    });

    it('forgets the generated password on pagehide, before the page can go into the back-forward cache', async () => {
        const user = userEvent.setup();
        renderDrawer();
        const panel = await drawer();
        await user.click(within(panel).getByRole('button', { name: 'Generate a new password' }));
        await within(panel).findByText(SECRET);
        window.dispatchEvent(new Event('pagehide'));
        expect(document.body.textContent).not.toContain(SECRET);
    });

    it('hides the generated password after Done', async () => {
        const user = userEvent.setup();
        renderDrawer();
        const panel = await drawer();
        await user.click(within(panel).getByRole('button', { name: 'Generate a new password' }));
        await within(panel).findByText(SECRET);
        await user.click(within(panel).getByRole('button', { name: 'Done' }));
        expect(document.body.textContent).not.toContain(SECRET);
        expect(within(panel).getByRole('button', { name: 'Generate a new password' })).toBeInTheDocument();
    });

    it('focuses Generate a new password when opened for a reset', async () => {
        renderDrawer('anna', 'password');
        const panel = await drawer();
        await waitFor(() => expect(within(panel).getByRole('button', { name: 'Generate a new password' })).toHaveFocus());
    });

    it('matches the switch copy to each state and toggles with disable and enable', async () => {
        const user = userEvent.setup();
        renderDrawer();
        const panel = await drawer();
        const on = within(panel).getByRole('switch', { name: 'Enabled — sign-ins allowed.' });
        expect(on).toHaveAttribute('aria-checked', 'true');

        await user.click(on);
        const off = await within(panel).findByRole('switch', { name: 'Disabled — keeps receiving mail; sign-ins stop.' });
        expect(off).toHaveAttribute('aria-checked', 'false');
        expect(await screen.findByText('Disabled anna@bücher.example.')).toBeInTheDocument();

        await user.click(off);
        await within(panel).findByRole('switch', { name: 'Enabled — sign-ins allowed.' });
        expect(writes()).toEqual([
            ['/domains/xn--bcher-kva.example/mailboxes/anna/disable', { method: 'POST' }],
            ['/domains/xn--bcher-kva.example/mailboxes/anna/enable', { method: 'POST' }],
        ]);
    });

    it('shows a disabled mailbox as disabled', async () => {
        renderDrawer('bob');
        const panel = await drawer('bob@bücher.example');
        const off = within(panel).getByRole('switch', { name: 'Disabled — keeps receiving mail; sign-ins stop.' });
        expect(off).toHaveAttribute('aria-checked', 'false');
    });

    it('lists the sources from the target-filtered forwarding list', async () => {
        state.forwarding = [forwardingRow('sales', ['anna@xn--bcher-kva.example', 'out@gmail.com']), forwardingRow('info', ['anna@xn--bcher-kva.example'])];
        renderDrawer();
        const panel = await drawer();
        const sales = await within(panel).findByRole('link', { name: 'sales@bücher.example' });
        expect(sales).toHaveAttribute('href', '/domains/xn--bcher-kva.example/forwarding?q=sales');
        expect(within(panel).getByRole('link', { name: 'info@bücher.example' })).toHaveAttribute(
            'href',
            '/domains/xn--bcher-kva.example/forwarding?q=info',
        );
        expect(mocked).toHaveBeenCalledWith('/domains/xn--bcher-kva.example/forwarding', {
            query: { target: 'anna@xn--bcher-kva.example', limit: 200 },
        });
    });

    it('opens the forwarding drawer with this mailbox as the first destination from + Add', async () => {
        const user = userEvent.setup();
        renderDrawer();
        const panel = await drawer();
        await user.click(await within(panel).findByRole('button', { name: '+ Add' }));
        const fwd = screen.getByRole('dialog', { name: 'New forwarding address' });
        expect(within(fwd).getByText('anna@xn--bcher-kva.example')).toBeInTheDocument();
        expect(within(fwd).queryByRole('combobox', { name: 'Domain' })).not.toBeInTheDocument();
        await user.click(within(fwd).getByRole('button', { name: 'Cancel' }));
        expect(screen.queryByRole('dialog', { name: 'New forwarding address' })).not.toBeInTheDocument();
        expect(screen.getByRole('dialog', { name: 'anna@bücher.example' })).toBeInTheDocument();
    });

    it('says when nothing forwards here', async () => {
        renderDrawer();
        const panel = await drawer();
        expect(await within(panel).findByText('No forwarding addresses point here yet.')).toBeInTheDocument();
    });

    it('deletes from the danger zone and closes both the dialog and the drawer', async () => {
        const user = userEvent.setup();
        renderDrawer();
        const panel = await drawer();
        await user.click(within(panel).getByRole('button', { name: 'Delete mailbox…' }));
        const dialog = await screen.findByRole('dialog', { name: 'Delete anna@bücher.example?' });
        await user.click(within(dialog).getByRole('button', { name: 'Delete mailbox' }));
        await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
        expect(writes()).toEqual([['/domains/xn--bcher-kva.example/mailboxes/anna', { method: 'DELETE' }]]);
    });

    it('drops the generated password when another mailbox opens', async () => {
        const user = userEvent.setup();
        renderDrawer();
        const panel = await drawer();
        await user.click(within(panel).getByRole('button', { name: 'Generate a new password' }));
        await within(panel).findByText(SECRET);
        // The harness switches the drawer's mailbox without closing it, as a parent could.
        await user.click(screen.getByRole('button', { name: 'Open bob', hidden: true }));
        await drawer('bob@bücher.example');
        expect(document.body.textContent).not.toContain(SECRET);
    });

    describe('setup instructions', () => {
        it('posts the recipient and language from the dialog, toasts, and closes it', async () => {
            const user = userEvent.setup();
            renderDrawer();
            const panel = await drawer();
            await user.click(await within(panel).findByRole('button', { name: 'Send setup instructions' }));
            const dialog = await screen.findByRole('dialog', { name: 'Send setup instructions for anna@bücher.example' });
            await user.type(within(dialog).getByLabelText('Send to'), 'owner@gmail.com');
            await user.selectOptions(within(dialog).getByLabelText('Email language'), 'fi');
            await user.click(within(dialog).getByRole('button', { name: 'Send' }));
            expect(await screen.findByText('Setup instructions sent to owner@gmail.com.')).toBeInTheDocument();
            expect(writes()).toEqual([
                ['/domains/xn--bcher-kva.example/mailboxes/anna/welcome', expect.objectContaining({ method: 'POST', json: { to: 'owner@gmail.com', locale: 'fi' } })],
            ]);
            expect(screen.queryByRole('dialog', { name: /Send setup instructions for/ })).not.toBeInTheDocument();
        });

        it('shows a 422 under the field and a 429 as a message, and keeps the dialog open', async () => {
            const user = userEvent.setup();
            renderDrawer();
            const panel = await drawer();
            await user.click(await within(panel).findByRole('button', { name: 'Send setup instructions' }));
            const dialog = await screen.findByRole('dialog', { name: /Send setup instructions for/ });
            await user.type(within(dialog).getByLabelText('Send to'), 'nope');
            mocked.mockImplementationOnce((() =>
                Promise.reject(
                    new ApiProblem({ status: 422, title: 'Unprocessable', code: 'validation_failed', errors: { to: ['Enter a valid email address.'] } }),
                )) as typeof apiFetch);
            await user.click(within(dialog).getByRole('button', { name: 'Send' }));
            expect(await within(dialog).findByText('Enter a valid email address.')).toBeInTheDocument();
            mocked.mockImplementationOnce((() =>
                Promise.reject(new ApiProblem({ status: 429, title: 'Too Many Requests', code: 'too_many_requests' }))) as typeof apiFetch);
            await user.click(within(dialog).getByRole('button', { name: 'Send' }));
            expect(await within(dialog).findByText(/10 setup emails an hour/)).toBeInTheDocument();
            expect(screen.getByRole('dialog', { name: /Send setup instructions for/ })).toBeInTheDocument();
        });

        it('is offered to a manager', async () => {
            state.role = 'manager';
            renderDrawer();
            const panel = await drawer();
            expect(await within(panel).findByRole('button', { name: 'Send setup instructions' })).toBeInTheDocument();
        });

        it('clears the error when the recipient changes', async () => {
            const user = userEvent.setup();
            renderDrawer();
            const panel = await drawer();
            await user.click(await within(panel).findByRole('button', { name: 'Send setup instructions' }));
            const dialog = await screen.findByRole('dialog', { name: /Send setup instructions for/ });
            await user.type(within(dialog).getByLabelText('Send to'), 'a@b.c');
            mocked.mockImplementationOnce((() =>
                Promise.reject(new ApiProblem({ status: 429, title: 'Too Many Requests', code: 'too_many_requests' }))) as typeof apiFetch);
            await user.click(within(dialog).getByRole('button', { name: 'Send' }));
            expect(await within(dialog).findByText(/10 setup emails an hour/)).toBeInTheDocument();
            await user.type(within(dialog).getByLabelText('Send to'), 'x');
            expect(within(dialog).queryByText(/10 setup emails an hour/)).not.toBeInTheDocument();
        });

        it.each(['viewer'] as const)('is not offered to a %s', async (role) => {
            state.role = role;
            renderDrawer();
            const panel = await drawer();
            expect(await within(panel).findByText('Anna Admin')).toBeInTheDocument();
            expect(within(panel).queryByRole('button', { name: 'Send setup instructions' })).not.toBeInTheDocument();
        });
    });
});
