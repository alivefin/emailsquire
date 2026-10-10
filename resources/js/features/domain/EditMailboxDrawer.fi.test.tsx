import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import { apiFetch, type ApiInit } from '../../api/client';
import { ApiProblem } from '../../api/problems';
import { setLocale } from '../../i18n';
import { mailbox, NAMES } from '../../test/domainServer';
import { renderRoutes } from '../../test/routing';
import { EditMailboxDrawer } from './EditMailboxDrawer';

vi.mock('../../api/client', () => ({ apiFetch: vi.fn() }));

const mocked = vi.mocked(apiFetch);

beforeEach(() => {
    mocked.mockReset();
    mocked.mockImplementation(((path: string, init: ApiInit = {}) => {
        void init;
        if (path === '/domains/names') return Promise.resolve({ data: NAMES });
        if (path.endsWith('/forwarding')) return Promise.resolve({ data: [], meta: { page: 1, per_page: 200, total: 0, last_page: 1 } });
        if (path.endsWith('/usage')) return Promise.reject(new ApiProblem({ status: 503, title: 'Service Unavailable' }));
        if (path.endsWith('/mailboxes/anna')) return Promise.resolve(mailbox('anna'));
        if (path.endsWith('/mailboxes/sales')) return Promise.resolve(mailbox('sales', { status: 'disabled' }));
        return Promise.reject(new ApiProblem({ status: 404, title: 'Not Found' }));
    }) as typeof apiFetch);
});

function open(local: string) {
    renderRoutes([{ path: '/', element: <EditMailboxDrawer open onClose={vi.fn()} domain="example.org" local={local} /> }], '/');
}

describe('EditMailboxDrawer in Finnish', () => {
    it('words the switch for an enabled mailbox', async () => {
        await setLocale('fi');
        open('anna');
        expect(await screen.findByRole('switch', { name: 'Käytössä — sisäänkirjautuminen sallittu.' })).toBeInTheDocument();
    });

    it('words the switch for a disabled mailbox', async () => {
        await setLocale('fi');
        open('sales');
        expect(
            await screen.findByRole('switch', { name: 'Pois käytöstä — vastaanottaa edelleen postia, sisäänkirjautuminen estetty.' }),
        ).toBeInTheDocument();
    });
});
