import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { apiFetch, type ApiInit } from '../../api/client';
import { ApiProblem } from '../../api/problems';
import { setLocale } from '../../i18n';
import { PORTAL_ME, portalApi } from '../../test/portalServer';
import { renderRoutes } from '../../test/routing';
import { PortalAliases } from './PortalAliases';
import type { PortalMe } from './usePortal';

vi.mock('../../api/client', () => ({
    apiFetch: vi.fn(),
    onSessionExpired: vi.fn(() => () => {}),
    onTwoFactorRequired: vi.fn(() => () => {}),
    onStepUpRequired: vi.fn(() => () => {}),
    ensureCsrf: vi.fn(),
}));

const routes = [{ path: '/my/aliases', element: <PortalAliases /> }];
const ALLOWED = { self_service: { forwarding: true, aliases: true, external_forwarding: false } };
const LOCKED = { self_service: { forwarding: true, aliases: false, external_forwarding: false } };

interface Options {
    me?: Partial<PortalMe>;
    post?: (local: string) => unknown;
    del?: (local: string) => unknown;
}

/** The portal with `locals` as the aliases on example.org; writes change the list unless a handler throws. */
function renderAliases(locals: string[], { me = ALLOWED, post, del }: Options = {}) {
    let current = [...locals];
    const list = () => ({ data: current.map((local) => ({ local, address: `${local}@example.org` })) });
    const handlers: Record<string, (init: ApiInit | undefined) => unknown> = {
        '/portal/aliases': (init) => {
            if (init?.method !== 'POST') return list();
            const local = (init.json as { local: string }).local;
            const answer = post ? post(local) : { local, address: `${local}@example.org` };
            current = [...current, local].sort();
            return answer;
        },
    };
    for (const local of [...locals, 'sales']) {
        handlers[`/portal/aliases/${local}`] = () => {
            if (del) del(local);
            current = current.filter((l) => l !== local);
            return undefined;
        };
    }
    vi.mocked(apiFetch).mockImplementation(portalApi('mailbox', handlers, { ...PORTAL_ME, ...me }));
    return renderRoutes(routes, '/my/aliases');
}

function calls(method: string): [string, unknown][] {
    return vi
        .mocked(apiFetch)
        .mock.calls.filter(([, init]) => init?.method === method)
        .map(([path, init]) => [path, init?.json]);
}

function meCalls(): number {
    return vi.mocked(apiFetch).mock.calls.filter(([path]) => path === '/portal/me').length;
}

beforeEach(() => {
    vi.mocked(apiFetch).mockReset();
});

describe('PortalAliases', () => {
    it('lists the aliases with Remove, and an Add field with the domain after it', async () => {
        renderAliases(['info', 'sales']);

        expect(await screen.findByRole('heading', { level: 1, name: 'Aliases' })).toBeInTheDocument();
        const list = await screen.findByRole('list', { name: 'Your aliases' });
        expect(within(list).getAllByRole('listitem').map((li) => li.textContent)).toEqual([
            'info@example.orgRemove',
            'sales@example.orgRemove',
        ]);
        expect(screen.getByRole('button', { name: 'Remove info@example.org' })).toBeInTheDocument();
        expect(screen.getByLabelText('New alias')).toBeInTheDocument();
        expect(screen.getByText('@example.org')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Add alias' })).toBeDisabled();
    });

    it('says when there are none', async () => {
        renderAliases([]);
        expect(await screen.findByText('You have no aliases.')).toBeInTheDocument();
    });

    it('adds an alias and refreshes the list and the summary', async () => {
        const user = userEvent.setup();
        renderAliases([]);

        await user.type(await screen.findByLabelText('New alias'), ' Sales ');
        const before = meCalls();
        await user.click(screen.getByRole('button', { name: 'Add alias' }));

        await waitFor(() => expect(calls('POST')).toEqual([['/portal/aliases', { local: 'sales' }]]));
        expect(await screen.findByText('sales@example.org added.')).toBeInTheDocument();
        expect(await screen.findByRole('button', { name: 'Remove sales@example.org' })).toBeInTheDocument();
        expect(screen.getByLabelText('New alias')).toHaveValue('');
        await waitFor(() => expect(meCalls()).toBeGreaterThan(before));
    });

    it('shows address_unavailable under the field', async () => {
        const user = userEvent.setup();
        renderAliases([], {
            post: () => {
                throw new ApiProblem({
                    status: 422,
                    title: 'The given data was invalid.',
                    code: 'address_unavailable',
                    detail: 'This address isn’t available.',
                    errors: { local: ['This address isn’t available.'] },
                });
            },
        });

        await user.type(await screen.findByLabelText('New alias'), 'postmaster{Enter}');
        expect(await screen.findByText('This address isn’t available.')).toBeInTheDocument();
        expect(screen.getByLabelText('New alias')).toHaveAttribute('aria-invalid', 'true');
        expect(screen.getByLabelText('New alias')).toHaveValue('postmaster');
    });

    it('shows too_many_aliases from the server', async () => {
        const user = userEvent.setup();
        renderAliases(['a1'], {
            post: () => {
                throw new ApiProblem({
                    status: 422,
                    title: 'The given data was invalid.',
                    code: 'too_many_aliases',
                    detail: 'You can have at most 20 aliases.',
                    errors: { local: ['You can have at most 20 aliases.'] },
                });
            },
        });
        await user.type(await screen.findByLabelText('New alias'), 'more{Enter}');
        expect(await screen.findByText('You can have at most 20 aliases.')).toBeInTheDocument();
    });

    it('offers no Add once there are 20', async () => {
        renderAliases(Array.from({ length: 20 }, (_, i) => `a${String(i).padStart(2, '0')}`));
        expect(await screen.findByText('You have 20 aliases, the most you can have. Remove one to add another.')).toBeInTheDocument();
        expect(screen.queryByLabelText('New alias')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Remove a00@example.org' })).toBeInTheDocument();
    });

    it('removes an alias after a confirmation that names what happens to its mail', async () => {
        const user = userEvent.setup();
        renderAliases(['info', 'sales']);

        await user.click(await screen.findByRole('button', { name: 'Remove sales@example.org' }));
        const dialog = await screen.findByRole('dialog', { name: 'Remove sales@example.org?' });
        expect(within(dialog).getByText('Mail sent to sales@example.org will be rejected.')).toBeInTheDocument();
        await user.click(within(dialog).getByRole('button', { name: 'Remove' }));

        await waitFor(() => expect(calls('DELETE')).toEqual([['/portal/aliases/sales', undefined]]));
        expect(await screen.findByText('sales@example.org removed.')).toBeInTheDocument();
        await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
        await waitFor(() => expect(screen.queryByRole('button', { name: 'Remove sales@example.org' })).not.toBeInTheDocument());
    });

    it('keeps the dialog open with the error when removing fails', async () => {
        const user = userEvent.setup();
        renderAliases(['sales'], {
            del: () => {
                throw new ApiProblem({ status: 429, title: 'Too Many Requests', code: 'too_many_requests' });
            },
        });
        await user.click(await screen.findByRole('button', { name: 'Remove sales@example.org' }));
        const dialog = await screen.findByRole('dialog', { name: 'Remove sales@example.org?' });
        await user.click(within(dialog).getByRole('button', { name: 'Remove' }));
        expect(await within(dialog).findByText('Too many attempts. Wait a minute and try again.')).toBeInTheDocument();
    });

    it('is a read-only list asking the administrator when the domain does not allow it', async () => {
        renderAliases(['info'], { me: LOCKED });

        expect(await screen.findByText('info@example.org')).toBeInTheDocument();
        expect(screen.getByText('Your administrator has turned this off.')).toBeInTheDocument();
        expect(screen.getByText('Ask your administrator to add one.')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /^Remove/ })).not.toBeInTheDocument();
        expect(screen.queryByLabelText('New alias')).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Add alias' })).not.toBeInTheDocument();
    });

    it('rereads the switches when the server says aliases were turned off meanwhile', async () => {
        const user = userEvent.setup();
        renderAliases([], {
            post: () => {
                throw new ApiProblem({ status: 403, title: 'Forbidden', code: 'self_service_disabled', detail: 'Your administrator has turned this off.' });
            },
        });
        const input = await screen.findByLabelText('New alias');
        const before = meCalls();
        await user.type(input, 'sales{Enter}');
        expect(await screen.findByText('Your administrator has turned this off.')).toBeInTheDocument();
        await waitFor(() => expect(meCalls()).toBeGreaterThan(before));
    });

    it('renders in Finnish', async () => {
        await setLocale('fi');
        renderAliases(['info'], { me: LOCKED });
        expect(await screen.findByRole('heading', { level: 1, name: 'Aliakset' })).toBeInTheDocument();
        expect(await screen.findByRole('list', { name: 'Aliaksesi' })).toBeInTheDocument();
        expect(screen.getByText('Ylläpitäjäsi on poistanut tämän käytöstä.')).toBeInTheDocument();
        expect(screen.getByText('Pyydä ylläpitäjääsi lisäämään alias.')).toBeInTheDocument();
    });
});
