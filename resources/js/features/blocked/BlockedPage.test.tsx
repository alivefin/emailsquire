import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { apiFetch, type ApiInit } from '../../api/client';
import { ApiProblem } from '../../api/problems';
import { useIsPhone } from '../../lib/useIsPhone';
import { routes } from '../../router';
import { ME, NAMES } from '../../test/domainServer';
import { renderRoutes } from '../../test/routing';

vi.mock('../../api/client', () => ({ apiFetch: vi.fn(), onSessionExpired: vi.fn(() => () => {}), onTwoFactorRequired: vi.fn(() => () => {}), onStepUpRequired: vi.fn(() => () => {}), ensureCsrf: vi.fn() }));
vi.mock('../../lib/useIsPhone', () => ({ useIsPhone: vi.fn(() => false) }));

const mocked = vi.mocked(apiFetch);

const ROW = { address: 'a+b@x.org', reason: 'spammed', created_at: '2026-01-02T10:00:00Z', created_by_name: 'Anna Admin' };
const BARE = { address: 'old@x.org', reason: null, created_at: null, created_by_name: null };

function serve(rows: unknown[], write?: (path: string, init: ApiInit) => unknown, me: unknown = ME) {
    mocked.mockImplementation(((path: string, init: ApiInit = {}) => {
        if ((init.method ?? 'GET') !== 'GET') {
            try {
                return Promise.resolve(write?.(path, init));
            } catch (e) {
                return Promise.reject(e);
            }
        }
        if (path === '/me') return Promise.resolve(me);
        if (path === '/me/pinned-domains') return Promise.resolve({ data: [] });
        if (path === '/blocked-addresses') return Promise.resolve({ data: rows, meta: { page: 1, per_page: 10, total: rows.length, last_page: 1 } });
        if (path === '/domains/names') return Promise.resolve({ data: NAMES });
        return Promise.resolve({});
    }) as typeof apiFetch);
}

function writes() {
    return mocked.mock.calls.filter(([, i]) => (i?.method ?? 'GET') !== 'GET');
}

beforeEach(() => {
    mocked.mockReset();
    vi.mocked(useIsPhone).mockReturnValue(false);
});

describe('BlockedPage', () => {
    it('lists address, reason, who and when', async () => {
        serve([ROW, BARE]);
        renderRoutes(routes, '/blocked');
        const row = (await screen.findByText('a+b@x.org')).closest('tr') as HTMLElement;
        expect(within(row).getByText('spammed')).toBeInTheDocument();
        expect(within(row).getByText('Anna Admin')).toBeInTheDocument();
    });

    it('shows the list without the form or Unblock to someone who is not a server admin', async () => {
        serve([ROW, BARE], undefined, { ...ME, is_server_admin: false });
        renderRoutes(routes, '/blocked');
        const row = (await screen.findByText('a+b@x.org')).closest('tr') as HTMLElement;
        expect(within(row).getByText('spammed')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Block' })).not.toBeInTheDocument();
        expect(screen.queryByText('Block an address')).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /^Unblock/ })).not.toBeInTheDocument();
        expect(screen.queryByRole('columnheader', { name: 'Actions' })).not.toBeInTheDocument();
    });

    it('shows the empty state', async () => {
        serve([]);
        renderRoutes(routes, '/blocked');
        expect(await screen.findByText('No blocked addresses.')).toBeInTheDocument();
    });

    it('refuses an incomplete address client-side', async () => {
        const user = userEvent.setup();
        serve([]);
        renderRoutes(routes, '/blocked');
        await screen.findByText('No blocked addresses.');
        await user.type(screen.getByLabelText('Address'), 'anna');
        await user.click(screen.getByRole('button', { name: 'Block' }));
        expect(await screen.findByText('Enter a full address like name@example.org.')).toBeInTheDocument();
        expect(writes()).toHaveLength(0);
    });

    it('blocks an address with a reason and toasts', async () => {
        const user = userEvent.setup();
        serve([], () => ({ address: 'x@y.org', reason: 'spam', created_at: null, created_by_name: null }));
        renderRoutes(routes, '/blocked');
        await screen.findByText('No blocked addresses.');
        await user.type(screen.getByLabelText('Address'), ' X@Y.org ');
        await user.type(screen.getByLabelText('Reason'), 'spam');
        await user.click(screen.getByRole('button', { name: 'Block' }));
        await waitFor(() => expect(writes()).toHaveLength(1));
        expect(writes()[0]).toEqual(['/blocked-addresses', { method: 'POST', json: { address: 'x@y.org', reason: 'spam' } }]);
        expect(await screen.findByText('Blocked x@y.org.')).toBeInTheDocument();
    });

    it('shows a 409 under the field', async () => {
        const user = userEvent.setup();
        serve([], () => {
            throw new ApiProblem({ status: 409, code: 'already_blocked', title: 'Conflict' });
        });
        renderRoutes(routes, '/blocked');
        await screen.findByText('No blocked addresses.');
        await user.type(screen.getByLabelText('Address'), 'x@y.org');
        await user.click(screen.getByRole('button', { name: 'Block' }));
        expect(await screen.findByText('That address is already blocked.')).toBeInTheDocument();
    });

    it('unblocks with the address URL-encoded, then Undo re-POSTs address and reason', async () => {
        const user = userEvent.setup();
        serve([ROW]);
        renderRoutes(routes, '/blocked');
        const row = (await screen.findByText('a+b@x.org')).closest('tr') as HTMLElement;
        await user.click(within(row).getByRole('button', { name: /Unblock/ }));
        await waitFor(() => expect(writes()).toHaveLength(1));
        expect(writes()[0]?.[0]).toBe('/blocked-addresses/a%2Bb%40x.org');
        expect(writes()[0]?.[1]).toEqual({ method: 'DELETE' });
        expect(await screen.findByText('Unblocked a+b@x.org.')).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Undo' }));
        await waitFor(() => expect(writes()).toHaveLength(2));
        expect(writes()[1]).toEqual(['/blocked-addresses', { method: 'POST', json: { address: 'a+b@x.org', reason: 'spammed' } }]);
    });
});
