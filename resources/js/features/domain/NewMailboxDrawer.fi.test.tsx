import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { apiFetch, type ApiInit } from '../../api/client';
import { setLocale } from '../../i18n';
import { NAMES } from '../../test/domainServer';
import { renderRoutes } from '../../test/routing';
import { NewMailboxDrawer } from './NewMailboxDrawer';

vi.mock('../../api/client', () => ({ apiFetch: vi.fn() }));

const mocked = vi.mocked(apiFetch);

beforeEach(() => {
    mocked.mockReset();
    mocked.mockImplementation(((path: string, init: ApiInit = {}) => {
        if ((init.method ?? 'GET') === 'POST') {
            const body = init.json as { local: string; quota_bytes: number };
            return Promise.resolve({ local: body.local, quota_bytes: body.quota_bytes });
        }
        if (path === '/domains/names') return Promise.resolve({ data: NAMES });
        if (path.endsWith('/addresses/check')) {
            return Promise.resolve({ address: 'new@example.org', status: 'available', reason: null, message: 'ok' });
        }
        return Promise.reject(new Error(`unexpected ${path}`));
    }) as typeof apiFetch);
});

describe('NewMailboxDrawer in Finnish', () => {
    it('titles the shown-once panel after the mailbox is created', async () => {
        const user = userEvent.setup();
        await setLocale('fi');
        renderRoutes([{ path: '/', element: <NewMailboxDrawer open onClose={vi.fn()} domain="example.org" /> }], '/');
        expect(screen.getByRole('dialog', { name: 'Uusi postilaatikko' })).toBeInTheDocument();
        await user.type(screen.getByLabelText('Osoite'), 'new');
        await user.type(screen.getByLabelText('Salasana'), 'Tr0ub4dor-horse');
        const create = screen.getByRole('button', { name: 'Luo postilaatikko' });
        await waitFor(() => expect(create).toBeEnabled());
        await user.click(create);
        expect(await screen.findByText('Salasana — näytetään vain kerran')).toBeInTheDocument();
        expect(screen.getByRole('dialog', { name: 'Postilaatikko luotu' })).toBeInTheDocument();
    });
});
