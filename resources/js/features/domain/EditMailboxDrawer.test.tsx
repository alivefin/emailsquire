import { useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { apiFetch, type ApiInit } from '../../api/client';
import { ApiProblem } from '../../api/problems';
import { ME, mailbox, NAMES } from '../../test/domainServer';
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
    /** `capabilities.schema_extension` in `me`; absent means the server lacks both columns. */
    ext?: { receiving: boolean; sendonly: boolean };
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
            if (path === '/me') {
                const ext = state.ext ?? { receiving: false, sendonly: false };
                return Promise.resolve({ ...ME, capabilities: { schema_extension: ext, catch_all: false } });
            }
            if (path.endsWith('/forwarding')) {
                return Promise.resolve({ data: state.forwarding, meta: { page: 1, per_page: 200, total: state.forwarding.length, last_page: 1 } });
            }
            if (action === 'usage') return state.usage instanceof ApiProblem ? Promise.reject(state.usage) : Promise.resolve(state.usage);
            if (box && action === undefined) return Promise.resolve(box);
            return Promise.reject(new ApiProblem({ status: 404, title: 'Not Found' }));
        }
        if (!box) return Promise.reject(new ApiProblem({ status: 404, title: 'Not Found' }));
        if (method === 'PATCH') {
            const body = init.json as { quota_bytes?: number; display_name?: string | null; receiving?: boolean; sendonly?: boolean };
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
        it('refetches the activity log after the welcome is queued', async () => {
            const user = userEvent.setup();
            const { client } = renderDrawer();
            const invalidate = vi.spyOn(client, 'invalidateQueries');
            const panel = await drawer();
            await user.click(await within(panel).findByRole('button', { name: 'Send setup instructions' }));
            const dialog = await screen.findByRole('dialog', { name: 'Send setup instructions for anna@bücher.example' });
            await user.type(within(dialog).getByLabelText('Send to'), 'owner@gmail.com');
            await user.click(within(dialog).getByRole('button', { name: 'Send' }));
            await screen.findByText('Setup instructions sent to owner@gmail.com.');
            expect(invalidate).toHaveBeenCalledWith({ queryKey: ['audit-log'] });
        });

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

describe('EditMailboxDrawer mail delivery switches', () => {
    function withExtension(receiving = true, sendonly = false) {
        state.ext = { receiving: true, sendonly: true };
        state.mailboxes.anna = { ...state.mailboxes.anna, receiving, sendonly };
    }

    it('hides the switches on a server without the schema extension', async () => {
        renderDrawer();
        const panel = await drawer();
        await within(panel).findByRole('switch', { name: 'Enabled — sign-ins allowed.' });
        expect(within(panel).queryByRole('switch', { name: 'Stop receiving mail' })).not.toBeInTheDocument();
        expect(within(panel).queryByRole('switch', { name: 'Send-only' })).not.toBeInTheDocument();
    });

    it('shows only the switch whose column exists', async () => {
        state.ext = { receiving: true, sendonly: false };
        // The column's value counts only where `me` says the server has the column.
        state.mailboxes.anna = { ...state.mailboxes.anna, receiving: true, sendonly: false };
        renderDrawer();
        const panel = await drawer();
        expect(await within(panel).findByRole('switch', { name: 'Stop receiving mail' })).toBeInTheDocument();
        expect(within(panel).queryByRole('switch', { name: 'Send-only' })).not.toBeInTheDocument();
    });

    it('hides a switch whose column me does not report, even when the mailbox has a value', async () => {
        state.ext = { receiving: false, sendonly: true };
        state.mailboxes.anna = { ...state.mailboxes.anna, receiving: true, sendonly: false };
        renderDrawer();
        const panel = await drawer();
        expect(await within(panel).findByRole('switch', { name: 'Send-only' })).toBeInTheDocument();
        expect(within(panel).queryByRole('switch', { name: 'Stop receiving mail' })).not.toBeInTheDocument();
    });

    it('stops receiving, and says what bounces, what still works and that the server needs the optional changes', async () => {
        const user = userEvent.setup();
        withExtension();
        renderDrawer();
        const panel = await drawer();
        const stop = await within(panel).findByRole('switch', { name: 'Stop receiving mail' });
        expect(stop).toHaveAttribute('aria-checked', 'false');
        expect(
            within(panel).getByText(
                'Mail sent straight to this address is refused. Mail from aliases and forwarding rules that point here bounces. The owner can still sign in, read old mail and send. A catch-all for the domain still takes mail sent to this address, and a forwarding rule that keeps a copy still forwards its other targets while its copy to this mailbox bounces. Works only after the server administrator has applied the optional mail server changes (backend plan, section 10).',
            ),
        ).toBeInTheDocument();
        expect(within(panel).getByText(/^This mailbox can send mail but receives none: mail sent straight to it is refused/)).toBeInTheDocument();
        await user.click(stop);
        await waitFor(() => expect(writes()).toEqual([['/domains/xn--bcher-kva.example/mailboxes/anna', { method: 'PATCH', json: { receiving: false } }]]));
        expect(await screen.findByText('anna@bücher.example no longer receives mail.')).toBeInTheDocument();
        await waitFor(() => expect(within(panel).getByRole('switch', { name: 'Stop receiving mail' })).toHaveAttribute('aria-checked', 'true'));
    });

    it('starts receiving again', async () => {
        const user = userEvent.setup();
        withExtension(false, false);
        renderDrawer();
        const panel = await drawer();
        await user.click(await within(panel).findByRole('switch', { name: 'Stop receiving mail' }));
        await waitFor(() => expect(writes()).toEqual([['/domains/xn--bcher-kva.example/mailboxes/anna', { method: 'PATCH', json: { receiving: true } }]]));
        expect(await screen.findByText('anna@bücher.example receives mail again.')).toBeInTheDocument();
    });

    it('turns on send-only', async () => {
        const user = userEvent.setup();
        withExtension();
        renderDrawer();
        const panel = await drawer();
        const sendOnly = await within(panel).findByRole('switch', { name: 'Send-only' });
        expect(sendOnly).toHaveAttribute('aria-checked', 'false');
        await user.click(sendOnly);
        await waitFor(() => expect(writes()).toEqual([['/domains/xn--bcher-kva.example/mailboxes/anna', { method: 'PATCH', json: { sendonly: true } }]]));
        expect(await screen.findByText('anna@bücher.example is now send-only.')).toBeInTheDocument();
    });

    it('shows a refused switch in words', async () => {
        const user = userEvent.setup();
        withExtension();
        renderDrawer();
        const panel = await drawer();
        const receive = await within(panel).findByRole('switch', { name: 'Stop receiving mail' });
        const message = "This server doesn't have the optional database columns for this setting.";
        mocked.mockImplementationOnce((() =>
            Promise.reject(
                new ApiProblem({ status: 422, title: 'Unprocessable', code: 'schema_extension_missing', errors: { receiving: [message] } }),
            )) as typeof apiFetch);
        await user.click(receive);
        expect(await screen.findByText(message)).toBeInTheDocument();
    });

    it('shows the switches read-only to a viewer', async () => {
        state.role = 'viewer';
        withExtension(false, true);
        renderDrawer();
        const panel = await drawer();
        expect(await within(panel).findByText('Stop receiving mail')).toBeInTheDocument();
        expect(within(panel).getByText('Stop receiving mail').nextElementSibling).toHaveTextContent('Yes');
        expect(within(panel).getByText('Send-only').nextElementSibling).toHaveTextContent('Yes');
        expect(within(panel).queryByRole('switch', { name: 'Stop receiving mail' })).not.toBeInTheDocument();
    });
});
