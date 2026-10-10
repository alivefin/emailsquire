import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { apiFetch, type ApiInit } from '../../api/client';
import { setLocale } from '../../i18n';
import { NAMES } from '../../test/domainServer';
import { renderRoutes } from '../../test/routing';
import { ForwardingDrawer } from './ForwardingDrawer';

vi.mock('../../api/client', () => ({ apiFetch: vi.fn() }));

const mocked = vi.mocked(apiFetch);
const D = 'xn--bcher-kva.example';

beforeEach(() => {
    mocked.mockReset();
    mocked.mockImplementation(((path: string, init: ApiInit = {}) => {
        if (path === '/domains/names') return Promise.resolve({ data: NAMES });
        if (path.endsWith('/addresses/check')) {
            return Promise.resolve({ address: `anna@${D}`, status: 'mailbox', reason: null, message: 'is already a mailbox.' });
        }
        if (path.endsWith('/mailboxes')) return Promise.resolve({ data: [], meta: { page: 1, per_page: 200, total: 0, last_page: 1 } });
        if (path.endsWith('/forwarding')) return Promise.resolve({ data: [], meta: { page: 1, per_page: 200, total: 0, last_page: 1 } });
        return Promise.reject(new Error(`unexpected ${path} ${init.method ?? 'GET'}`));
    }) as typeof apiFetch);
});

describe('ForwardingDrawer in Finnish', () => {
    it('asks for confirmation, with the source address, before keep-a-copy is turned off', async () => {
        const user = userEvent.setup();
        await setLocale('fi');
        renderRoutes([{ path: '/', element: <ForwardingDrawer open onClose={vi.fn()} domain={D} /> }], '/');
        await user.type(screen.getByLabelText('Osoite, joka vastaanottaa viestit'), 'anna');
        await user.click(await screen.findByRole('switch', { name: 'Säilytä kopio postilaatikossa' }));
        expect(
            await screen.findByText('Osoitteeseen anna@bücher.example tuleva posti lähetetään vain edelleen, sitä ei säilytetä.'),
        ).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Vahvista' })).toBeInTheDocument();
    });
});
