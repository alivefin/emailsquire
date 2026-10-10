import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { apiFetch } from '../../api/client';
import { setLocale } from '../../i18n';
import { useIsPhone } from '../../lib/useIsPhone';
import { routes } from '../../router';
import { fakeServer } from '../../test/domainServer';
import { renderRoutes } from '../../test/routing';

vi.mock('../../api/client', () => ({ apiFetch: vi.fn(), onSessionExpired: vi.fn(() => () => {}), ensureCsrf: vi.fn() }));
vi.mock('../../lib/useIsPhone', () => ({ useIsPhone: vi.fn(() => false) }));

beforeEach(() => {
    vi.mocked(apiFetch).mockReset();
    vi.mocked(useIsPhone).mockReturnValue(false);
});

describe('MailboxesTab in Finnish', () => {
    it('inflects the bulk disable toast for one mailbox', async () => {
        const user = userEvent.setup();
        fakeServer();
        await setLocale('fi');
        renderRoutes(routes, '/domains/example.org/mailboxes');
        await screen.findByText('anna@example.org');
        await user.click(screen.getByRole('checkbox', { name: 'Valitse sales' }));
        await user.click(screen.getByRole('button', { name: 'Poista käytöstä' }));
        expect(await screen.findByText('Poistettiin käytöstä 1 postilaatikko.')).toBeInTheDocument();
    });

    it('inflects the bulk disable toast for three mailboxes', async () => {
        const user = userEvent.setup();
        fakeServer();
        await setLocale('fi');
        renderRoutes(routes, '/domains/example.org/mailboxes');
        await screen.findByText('anna@example.org');
        await user.click(screen.getByRole('checkbox', { name: 'Valitse kaikki tämän sivun rivit' }));
        await user.click(screen.getByRole('button', { name: 'Poista käytöstä' }));
        expect(await screen.findByText('Poistettiin käytöstä 3 postilaatikkoa.')).toBeInTheDocument();
    });
});
