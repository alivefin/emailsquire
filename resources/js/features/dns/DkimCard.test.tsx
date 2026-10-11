import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { apiFetch, type ApiInit } from '../../api/client';
import { ApiProblem } from '../../api/problems';
import { checks, dkim, DKIM_TXT, dnsServer, dnsState, dnsWrites, publish, reads, type DnsState } from '../../test/dnsServer';
import { renderRoutes } from '../../test/routing';
import { DkimCard } from './DkimCard';

vi.mock('../../api/client', () => ({ apiFetch: vi.fn() }));

let state: DnsState;

beforeEach(() => {
    vi.mocked(apiFetch).mockReset();
    state = dnsState();
    dnsServer(state);
});

afterEach(() => {
    vi.useRealTimers();
});

function renderCard(props: { admin?: boolean; onTaskDone?: () => void; check?: ReturnType<typeof checks>['dkim'] } = {}) {
    const { admin = true, onTaskDone = vi.fn(), check = checks().dkim } = props;
    return renderRoutes(
        [{ path: '/', element: <DkimCard domain="example.org" unicode="example.org" admin={admin} check={check} onTaskDone={onTaskDone} /> }],
        '/',
    );
}

const card = () => screen.findByRole('region', { name: 'DKIM keys' });
const keyRow = async (selector: string) => (await within(await card()).findByText(selector)).closest('li') as HTMLElement;

/** A write answering 202 with the read body, now with a pending task of `type`. */
function queued(type: string) {
    return (path: string, init: ApiInit) => {
        void path;
        void init;
        const body = dkim({ pending_task: { type, status: 'pending', error: null } });
        state.dkim = [body];
        return Promise.resolve(body);
    };
}

describe('DkimCard', () => {
    it('lists the keys with their state, hiding retired ones', async () => {
        renderCard();
        const active = await keyRow('es20261001');
        expect(within(active).getByText('Active')).toBeInTheDocument();
        expect(within(active).getByText('Not in DNS yet')).toBeInTheDocument();
        const pending = await keyRow('es20261011');
        // Pending or replaced by a newer key alike: not active.
        expect(within(pending).getByText('Not active')).toBeInTheDocument();
        expect(within(await card()).queryByText('es20250101')).not.toBeInTheDocument();
        // The record wraps, monospaced.
        expect(within(pending).getByText(DKIM_TXT)).toHaveClass('font-mono', 'break-all');
        // The active key can't be activated or retired again.
        expect(within(active).queryByRole('button', { name: /Activate|Retire/ })).not.toBeInTheDocument();
    });

    it('offers no Activate or Retire for a key the console did not name, and says how to replace it', async () => {
        state.dkim = [
            dkim({
                keys: [
                    { selector: 'dkim', state: 'pending', txt: DKIM_TXT, created_at: '2026-10-01T08:00:00Z' },
                    { selector: 'es20261011', state: 'pending', txt: DKIM_TXT, created_at: '2026-10-11T08:00:00Z' },
                ],
            }),
        ];
        renderCard();
        const fallback = await keyRow('dkim');
        expect(within(fallback).queryByRole('button', { name: /Activate|Retire/ })).not.toBeInTheDocument();
        expect(within(fallback).getByText(/generating and activating a new key/)).toBeInTheDocument();
        const own = await keyRow('es20261011');
        expect(within(own).getByRole('button', { name: /Activate/ })).toBeInTheDocument();
        expect(within(own).queryByText(/generating and activating a new key/)).not.toBeInTheDocument();
    });

    it('marks a key DNS shows', async () => {
        const check = { ...checks().dkim!, selectors: [] as NonNullable<ReturnType<typeof checks>['dkim']>['selectors'] };
        check.selectors = [
            { selector: 'es20261011', state: 'pending', published: true, p_sha256: 'b2', publish: publish('es20261011._domainkey.example.org.', 'TXT', DKIM_TXT) },
        ];
        renderCard({ check });
        expect(within(await keyRow('es20261011')).getByText('DNS shows its record')).toBeInTheDocument();
    });

    it('judges a key by the record the check looked for, not its selector: a reused selector with a new key', async () => {
        const OLD_TXT = 'v=DKIM1; k=rsa; p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAoldkeyoldkeyoldkeyoldkey';
        const check = { ...checks().dkim!, selectors: [] as NonNullable<ReturnType<typeof checks>['dkim']>['selectors'] };
        // The stored check saw es20261011 published, but for the key that had that selector before.
        check.selectors = [
            { selector: 'es20261011', state: 'pending', published: true, p_sha256: 'old', publish: publish('es20261011._domainkey.example.org.', 'TXT', OLD_TXT) },
        ];
        renderCard({ check });
        const row = await keyRow('es20261011');
        expect(within(row).queryByText('DNS shows its record')).not.toBeInTheDocument();
        expect(within(row).queryByText('Not in DNS yet')).not.toBeInTheDocument();
        // The record shown is the key's own, not the old one the check looked for.
        expect(within(row).getByText(DKIM_TXT)).toBeInTheDocument();
        expect(within(row).queryByText(OLD_TXT)).not.toBeInTheDocument();
    });

    it('makes the header buttons full width on a phone', async () => {
        renderCard();
        const inspect = await within(await card()).findByRole('button', { name: 'Read keys from the server' });
        expect(inspect).toHaveClass('max-md:flex-1');
        expect(within(await card()).getByRole('button', { name: 'Create a new key' })).toHaveClass('max-md:flex-1');
    });

    it('creates a new key behind a confirmation', async () => {
        const user = userEvent.setup();
        state.write = queued('dkim.generate');
        renderCard();
        await user.click(await within(await card()).findByRole('button', { name: 'Create a new key' }));
        const dialog = await screen.findByRole('dialog', { name: 'Create a new DKIM key?' });
        expect(dnsWrites()).toEqual([]);
        await user.click(within(dialog).getByRole('button', { name: 'Create key' }));
        await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
        expect(dnsWrites()).toEqual([['/domains/example.org/dkim/generate', { method: 'POST' }]]);
        expect(await screen.findByText('Creating a new key on the server…')).toBeInTheDocument();
        expect(screen.getByText('The server is creating the key.')).toBeInTheDocument();
    });

    it('warns when DNS does not show the key and activates anyway with force', async () => {
        const user = userEvent.setup();
        state.write = (path, init) => {
            const json = init.json as { force?: boolean } | undefined;
            if (json?.force !== true) {
                return Promise.reject(
                    new ApiProblem({ status: 409, title: 'Conflict', code: 'dkim_not_published', detail: 'DNS does not show es20261011 yet.' }),
                );
            }
            return queued('dkim.activate')(path, init);
        };
        renderCard();
        await user.click(within(await keyRow('es20261011')).getByRole('button', { name: 'Activate es20261011' }));
        const dialog = await screen.findByRole('dialog', { name: 'Activate es20261011?' });
        await user.click(within(dialog).getByRole('button', { name: 'Activate' }));

        const warning = await screen.findByRole('dialog', { name: "DNS doesn't show es20261011 yet" });
        expect(within(warning).getByText(/did not find this key's record/)).toBeInTheDocument();
        await user.click(within(warning).getByRole('button', { name: 'Activate anyway' }));
        await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
        expect(dnsWrites()).toEqual([
            ['/domains/example.org/dkim/es20261011/activate', { method: 'POST', json: { force: false } }],
            ['/domains/example.org/dkim/es20261011/activate', { method: 'POST', json: { force: true } }],
        ]);
        expect(await screen.findByText('Switching rspamd to the new key…')).toBeInTheDocument();
    });

    it('keeps a refused retire in the dialog', async () => {
        const user = userEvent.setup();
        const detail = 'The key was activated less than 15 minutes ago, and rspamd may still sign with the previous key. Try again later.';
        state.write = () => Promise.reject(new ApiProblem({ status: 409, title: 'Conflict', code: 'dkim_recently_activated', detail }));
        renderCard();
        await user.click(within(await keyRow('es20261011')).getByRole('button', { name: 'Retire es20261011' }));
        const dialog = await screen.findByRole('dialog', { name: 'Retire es20261011?' });
        await user.click(within(dialog).getByRole('button', { name: 'Retire key' }));
        expect(await within(dialog).findByRole('alert')).toHaveTextContent(detail);
        expect(dnsWrites()).toEqual([['/domains/example.org/dkim/es20261011/retire', { method: 'POST' }]]);
    });

    it('stays quiet when the step-up is cancelled', async () => {
        const user = userEvent.setup();
        state.write = () => Promise.reject(new ApiProblem({ status: 423, title: 'Locked', code: 'step_up_required', cancelled: true }));
        renderCard();
        await user.click(within(await keyRow('es20261011')).getByRole('button', { name: 'Retire es20261011' }));
        const dialog = await screen.findByRole('dialog', { name: 'Retire es20261011?' });
        await user.click(within(dialog).getByRole('button', { name: 'Retire key' }));
        await waitFor(() => expect(within(dialog).getByRole('button', { name: 'Retire key' })).toBeEnabled());
        expect(within(dialog).queryByRole('alert')).not.toBeInTheDocument();
    });

    it('polls every 5 seconds while a task is pending and stops once it is done', async () => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        const onTaskDone = vi.fn();
        state.dkim = [
            dkim({ pending_task: { type: 'dkim.retire', status: 'pending', error: null } }),
            dkim({ pending_task: { type: 'dkim.retire', status: 'running', error: null } }),
            dkim(),
        ];
        renderCard({ onTaskDone });
        expect(await screen.findByText('Deleting the key file…')).toBeInTheDocument();
        expect(reads(/\/dkim$/)).toBe(1);
        // Nothing to act on while the helper works.
        expect(within(await keyRow('es20261011')).getByRole('button', { name: 'Retire es20261011' })).toBeDisabled();

        await act(() => vi.advanceTimersByTimeAsync(5000));
        await waitFor(() => expect(reads(/\/dkim$/)).toBe(2));
        await act(() => vi.advanceTimersByTimeAsync(5000));
        await waitFor(() => expect(reads(/\/dkim$/)).toBe(3));
        await waitFor(() => expect(screen.queryByText('Deleting the key file…')).not.toBeInTheDocument());
        expect(onTaskDone).toHaveBeenCalledTimes(1);

        await act(() => vi.advanceTimersByTimeAsync(20000));
        expect(reads(/\/dkim$/)).toBe(3);
    });

    it('shows a failed task with its error and does not poll', async () => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        state.dkim = [dkim({ pending_task: { type: 'dkim.generate', status: 'failed', error: 'the dkim directory is not writable' } })];
        renderCard();
        expect(await screen.findByText('The last DKIM task failed.')).toBeInTheDocument();
        expect(screen.getByText('the dkim directory is not writable')).toBeInTheDocument();
        await act(() => vi.advanceTimersByTimeAsync(15000));
        expect(reads(/\/dkim$/)).toBe(1);
    });

    it('shows the keys read-only to a manager or viewer', async () => {
        renderCard({ admin: false });
        const pending = await keyRow('es20261011');
        expect(within(pending).queryByRole('button', { name: /Activate|Retire/ })).not.toBeInTheDocument();
        expect(within(await card()).queryByRole('button', { name: 'Create a new key' })).not.toBeInTheDocument();
        expect(within(pending).getByText(DKIM_TXT)).toBeInTheDocument();
    });
});
