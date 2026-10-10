import { describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import { setLocale } from '../../i18n';
import { renderRoutes } from '../../test/routing';
import { DeleteDomainDialog } from './DeleteDomainDialog';

vi.mock('../../api/client', () => ({ apiFetch: vi.fn() }));

function open(mailboxes: number, forwarding: number) {
    const domain = { name: 'example.org', unicode_name: 'example.org', mailbox_count: mailboxes, forwarding_count: forwarding };
    renderRoutes([{ path: '/', element: <DeleteDomainDialog open onClose={vi.fn()} domain={domain} /> }], '/');
}

describe('DeleteDomainDialog in Finnish', () => {
    it.each([
        [0, 0, '0 postilaatikkoa ja 0 edelleenlähetysosoitetta poistetaan.'],
        [1, 1, '1 postilaatikko ja 1 edelleenlähetysosoite poistetaan.'],
        [2, 2, '2 postilaatikkoa ja 2 edelleenlähetysosoitetta poistetaan.'],
    ])('pluralises %i mailboxes and %i forwarding addresses', async (mailboxes, forwarding, sentence) => {
        await setLocale('fi');
        open(mailboxes, forwarding);
        expect(screen.getByText(new RegExp(`^${sentence.replace('.', '\\.')}`))).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Poista verkkotunnus' })).toBeDisabled();
        expect(screen.getByLabelText(/Vahvista kirjoittamalla example\.org/)).toBeInTheDocument();
    });
});
