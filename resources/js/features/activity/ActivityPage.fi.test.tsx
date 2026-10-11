import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import { apiFetch } from '../../api/client';
import { setLocale } from '../../i18n';
import { renderRoutes } from '../../test/routing';
import { ActivityPage } from './ActivityPage';

vi.mock('../../api/client', () => ({ apiFetch: vi.fn() }));
vi.mock('../../lib/useIsPhone', () => ({ useIsPhone: vi.fn(() => false) }));

const ADMIN = { id: 1, name: 'Anna Admin', email: 'anna@example.org', is_server_admin: true, managers_can_share: false, landing: null };

beforeEach(() => {
    vi.mocked(apiFetch).mockReset();
    vi.mocked(apiFetch).mockImplementation(((path: string) => {
        if (path === '/me') return Promise.resolve(ADMIN);
        if (path === '/domains/names') return Promise.resolve({ data: [{ name: 'example.org', unicode_name: 'example.org', mailbox_count: 1, role: 'admin' }] });
        if (path === '/audit-log/actors') return Promise.resolve({ data: [{ key: '1', kind: 'user', name: 'Anna Admin' }, { key: 'cli', kind: 'cli', name: 'Command line' }] });
        return Promise.resolve({
            data: [
                {
                    id: 3,
                    occurred_at: new Date().toISOString(),
                    actor_kind: 'you',
                    actor_name: 'Anna Admin',
                    action: 'mailbox.welcome_sent',
                    object_type: 'mailbox',
                    object_key: 'anna@example.org',
                    domain_name: 'example.org',
                    changes: { to: 'anna.home@example.net' },
                },
            ],
            meta: { page: 1, per_page: 10, total: 1, last_page: 1 },
        });
    }) as typeof apiFetch);
});

describe('ActivityPage in Finnish', () => {
    it('words the filters, the columns and the row in Finnish', async () => {
        await setLocale('fi');
        renderRoutes([{ path: '/activity', element: <ActivityPage /> }], '/activity?group=mailboxes');

        const cell = await screen.findByText('lähetit postilaatikon anna@example.org käyttöönotto-ohjeet osoitteeseen anna.home@example.net');
        const tr = cell.closest('tr')!;
        expect(within(tr).getAllByText('Sinä')).toHaveLength(2);
        expect(within(tr).getByText('juuri nyt')).toBeInTheDocument();
        for (const name of ['Aika', 'Henkilö', 'Tapahtuma', 'Kohde', 'Verkkotunnus']) {
            expect(screen.getByRole('columnheader', { name })).toBeInTheDocument();
        }
        const group = screen.getByRole('combobox', { name: 'Tapahtumatyyppi' });
        expect(group).toHaveValue('mailboxes');
        expect(within(group).getByRole('option', { name: 'Postilaatikot' })).toBeInTheDocument();
        expect(within(group).getByRole('option', { name: 'Kaikki tapahtumat' })).toBeInTheDocument();
        const person = screen.getByRole('combobox', { name: 'Henkilö' });
        expect(await within(person).findByRole('option', { name: 'Anna Admin (sinä)' })).toBeInTheDocument();
        expect(within(person).getByRole('option', { name: 'Komentorivi' })).toBeInTheDocument();
        expect(screen.getByLabelText('Alkupäivä')).toBeInTheDocument();
        expect(screen.getByLabelText('Loppupäivä')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Tyhjennä suodattimet' })).toBeInTheDocument();
        expect(screen.getByText('1 tapahtuma')).toBeInTheDocument();
    });
});
