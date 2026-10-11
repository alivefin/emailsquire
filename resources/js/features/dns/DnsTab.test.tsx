import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { apiFetch } from '../../api/client';
import { ApiProblem } from '../../api/problems';
import { useIsPhone } from '../../lib/useIsPhone';
import { checks, dkim, DKIM_TXT, dnsServer, dnsState, dnsWrites, reads, type DnsState } from '../../test/dnsServer';
import { renderRoutes } from '../../test/routing';
import { DnsTab } from './DnsTab';

vi.mock('../../api/client', () => ({ apiFetch: vi.fn() }));
vi.mock('../../lib/useIsPhone', () => ({ useIsPhone: vi.fn(() => false) }));

let state: DnsState;

beforeEach(() => {
    vi.mocked(apiFetch).mockReset();
    vi.mocked(useIsPhone).mockReturnValue(false);
    state = dnsState();
    dnsServer(state);
});

afterEach(() => {
    vi.useRealTimers();
});

function renderTab() {
    return renderRoutes([{ path: '/domains/:domain/dns', element: <DnsTab /> }], '/domains/example.org/dns');
}

const check = (name: string) => screen.findByRole('region', { name });

describe('DnsTab', () => {
    it('shows a row per check with its status, what was found and the hint', async () => {
        renderTab();
        const mx = await check('MX');
        expect(within(mx).getByText('OK')).toBeInTheDocument();
        expect(within(mx).getByText('10 mail.example.org')).toBeInTheDocument();
        expect(within(mx).getByText('Mail for the domain is delivered to this server.')).toBeInTheDocument();
        expect(within(mx).queryByText('Publish this:')).not.toBeInTheDocument();

        const spf = await check('SPF');
        expect(within(spf).getByText('Warning')).toBeInTheDocument();
        expect(within(spf).getByText(/may be covered by an include: or redirect= term/)).toBeInTheDocument();

        const dmarc = await check('DMARC');
        expect(within(dmarc).getByText('Problem')).toBeInTheDocument();
        expect(within(dmarc).getByText('Found:').parentElement).toHaveTextContent('Found: nothing');

        const sts = await check('MTA-STS');
        expect(within(sts).getByText('Optional')).toBeInTheDocument();
        expect(within(sts).queryByText('Publish this:')).not.toBeInTheDocument();

        expect(await check('PTR (reverse DNS)')).toBeInTheDocument();
        expect(await check('Autoconfig')).toBeInTheDocument();
        expect(screen.getByText(/^Checked 5 min ago$/)).toBeInTheDocument();
    });

    it('leaves out "Expected:" where the record to publish is shown, and keeps it where none is', async () => {
        const mx = { status: 'warn' as const, found: ['10 mx.elsewhere.net', '20 mail.example.org'], expected: 'mail.example.org', hint_key: 'mx.lower_priority' };
        state.dns = { ...state.dns, result: { ...state.dns.result!, checks: { ...checks(), mx } } };
        renderTab();
        const dkimRow = await check('DKIM');
        expect(within(dkimRow).getByText('Publish this:')).toBeInTheDocument();
        expect(within(dkimRow).queryByText('Expected:')).not.toBeInTheDocument();

        const mxRow = await check('MX');
        expect(within(mxRow).getByText('Expected:')).toBeInTheDocument();
        expect(within(mxRow).getByText(/Another server has an MX with a lower preference number/)).toBeInTheDocument();
    });

    it('shows the record to publish for a check that is not ok, monospaced', async () => {
        renderTab();
        const dmarc = await check('DMARC');
        expect(within(dmarc).getByText('Publish this:')).toBeInTheDocument();
        expect(within(dmarc).getByText('_dmarc.example.org.')).toBeInTheDocument();
        const value = within(dmarc).getByText('v=DMARC1; p=none; rua=mailto:postmaster@example.org');
        expect(value).toHaveClass('font-mono');
        expect(within(dmarc).getByText('_dmarc.example.org. 3600 IN TXT "v=DMARC1; p=none; rua=mailto:postmaster@example.org"')).toHaveClass(
            'font-mono',
        );

        const dkimRow = await check('DKIM');
        // A long DKIM value wraps instead of widening the page.
        expect(within(dkimRow).getAllByText(DKIM_TXT)[0]).toHaveClass('break-all');
    });

    it('copies the value and the zone line', async () => {
        const user = userEvent.setup();
        renderTab();
        const dmarc = await check('DMARC');
        await user.click(within(dmarc).getByRole('button', { name: 'Copy the TXT value for _dmarc.example.org.' }));
        expect(await navigator.clipboard.readText()).toBe('v=DMARC1; p=none; rua=mailto:postmaster@example.org');
        expect(within(dmarc).getByRole('button', { name: 'Copied the TXT value for _dmarc.example.org.' })).toHaveTextContent('Copied');

        await user.click(within(dmarc).getByRole('button', { name: 'Copy the zone file line for _dmarc.example.org.' }));
        expect(await navigator.clipboard.readText()).toBe('_dmarc.example.org. 3600 IN TXT "v=DMARC1; p=none; rua=mailto:postmaster@example.org"');
    });

    it('checks again on Check now and polls every 5 seconds until the check has run', async () => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        const user = userEvent.setup({ advanceTimers: (ms) => vi.advanceTimersByTime(ms) });
        const fresh = checks();
        fresh.dmarc = { status: 'ok', found: ['v=DMARC1; p=none'], hint_key: 'dmarc.ok' };
        state.write = () => {
            // The job runs in the background: the 202 answers the old result, the next read the new one.
            const old = state.dns.result;
            state.dns = { result: { checked_at: new Date().toISOString(), checks: fresh }, checking: false };
            return Promise.resolve({ result: old, checking: true });
        };
        renderTab();
        await user.click(await screen.findByRole('button', { name: 'Check now' }));
        expect(dnsWrites()).toEqual([['/domains/example.org/dns/recheck', { method: 'POST' }]]);
        expect(await screen.findByText('Checking DNS…')).toBeInTheDocument();
        const before = reads(/\/dns$/);
        await act(() => vi.advanceTimersByTimeAsync(5000));
        await waitFor(() => expect(within(screen.getByRole('region', { name: 'DMARC' })).getByText('OK')).toBeInTheDocument());
        expect(reads(/\/dns$/)).toBe(before + 1);
        expect(screen.getByText('Checked just now')).toBeInTheDocument();
        expect(screen.queryByText('Checking DNS…')).not.toBeInTheDocument();
        // Done: no more polling.
        await act(() => vi.advanceTimersByTimeAsync(15000));
        expect(reads(/\/dns$/)).toBe(before + 1);
    });

    it('says inline when the domain was checked less than a minute ago', async () => {
        const user = userEvent.setup();
        state.write = () => Promise.reject(new ApiProblem({ status: 429, title: 'Too Many Requests', code: 'too_many_requests' }));
        renderTab();
        await user.click(await screen.findByRole('button', { name: 'Check now' }));
        expect(await screen.findByRole('alert')).toHaveTextContent('DNS was checked less than a minute ago. Try again in a minute.');
    });

    it('shows the first check while it runs', async () => {
        state.dns = { result: null, checking: true };
        renderTab();
        expect(await screen.findByText('The first DNS check is running. This takes up to a minute.')).toBeInTheDocument();
        expect(screen.queryByRole('region', { name: 'MX' })).not.toBeInTheDocument();
    });

    it('lets a viewer read the checks and the records, without Check now or DKIM actions', async () => {
        state.role = 'viewer';
        renderTab();
        const dmarc = await check('DMARC');
        expect(within(dmarc).getByText('Publish this:')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Check now' })).not.toBeInTheDocument();
        const card = await screen.findByRole('region', { name: 'DKIM keys' });
        expect(await within(card).findByText('es20261011')).toBeInTheDocument();
        expect(within(card).queryByRole('button', { name: 'Create a new key' })).not.toBeInTheDocument();
    });

    it('lets a manager check now but not change DKIM keys', async () => {
        state.role = 'manager';
        renderTab();
        expect(await screen.findByRole('button', { name: 'Check now' })).toBeInTheDocument();
        const card = await screen.findByRole('region', { name: 'DKIM keys' });
        expect(await within(card).findByText('es20261011')).toBeInTheDocument();
        expect(within(card).queryByRole('button', { name: /Activate/ })).not.toBeInTheDocument();
        expect(within(card).getByText('Only server admins can create, activate and retire keys.')).toBeInTheDocument();
    });

    it('checks DNS again once a DKIM task is done, so a new key shows', async () => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        state.dkim = [dkim({ pending_task: { type: 'dkim.generate', status: 'running', error: null } }), dkim()];
        state.write = () => Promise.resolve({ result: state.dns.result, checking: false });
        renderTab();
        expect(await screen.findByText('Creating a new key on the server…')).toBeInTheDocument();
        expect(dnsWrites()).toEqual([]);
        await act(() => vi.advanceTimersByTimeAsync(5000));
        await waitFor(() => expect(dnsWrites()).toEqual([['/domains/example.org/dns/recheck', { method: 'POST' }]]));
        expect(screen.queryByText('Creating a new key on the server…')).not.toBeInTheDocument();
    });

    it('stacks the checks as cards on a phone', async () => {
        vi.mocked(useIsPhone).mockReturnValue(true);
        renderTab();
        const mx = await check('MX');
        expect(mx).toHaveClass('rounded-[10px]', 'border');
        expect(reads(/\/dns$/)).toBe(1);
    });
});
