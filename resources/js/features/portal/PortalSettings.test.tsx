import { beforeEach, describe, expect, it, vi } from 'vitest';
import { act, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { apiFetch } from '../../api/client';
import { setLocale } from '../../i18n';
import { PORTAL_ME, portalApi } from '../../test/portalServer';
import { renderRoutes } from '../../test/routing';
import type { PortalMe } from './usePortal';
import { PortalSettings } from './PortalSettings';

vi.mock('../../api/client', () => ({
    apiFetch: vi.fn(),
    onSessionExpired: vi.fn(() => () => {}),
    onTwoFactorRequired: vi.fn(() => () => {}),
    onStepUpRequired: vi.fn(() => () => {}),
    ensureCsrf: vi.fn(),
}));

const routes = [{ path: '/my/settings', element: <PortalSettings /> }];

function renderSettings(me: Partial<PortalMe> = {}) {
    vi.mocked(apiFetch).mockImplementation(portalApi('mailbox', {}, { ...PORTAL_ME, ...me }));
    return renderRoutes(routes, '/my/settings');
}

beforeEach(() => {
    vi.mocked(apiFetch).mockReset();
    vi.restoreAllMocks();
});

describe('PortalSettings', () => {
    it('shows the username, both servers with their ports and security, and the webmail link', async () => {
        renderSettings();

        expect(await screen.findByRole('heading', { level: 1, name: 'Email app settings' })).toBeInTheDocument();
        const incoming = screen.getByRole('region', { name: 'Incoming mail (IMAP)' });
        expect(within(incoming).getByText('imap.example.net')).toBeInTheDocument();
        expect(within(incoming).getByText('993')).toBeInTheDocument();
        expect(within(incoming).getByText('SSL/TLS')).toBeInTheDocument();
        const outgoing = screen.getByRole('region', { name: 'Outgoing mail (SMTP)' });
        expect(within(outgoing).getByText('smtp.example.net')).toBeInTheDocument();
        expect(within(outgoing).getByText('587')).toBeInTheDocument();
        expect(within(outgoing).getByText('STARTTLS')).toBeInTheDocument();
        expect(screen.getByText('anna@example.org')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Open webmail' })).toHaveAttribute('href', 'https://webmail.example.net/');
    });

    it('copies the username and each server name', async () => {
        renderSettings();
        const user = userEvent.setup();
        const writeText = vi.spyOn(navigator.clipboard, 'writeText');

        const username = await screen.findByRole('button', { name: 'Copy the username' });
        await user.click(username);
        expect(writeText).toHaveBeenLastCalledWith('anna@example.org');
        await waitFor(() => expect(username).toHaveTextContent('Copied'));
        // Screen readers hear it from a live region; the button keeps its name and the focus.
        expect(screen.getByTestId('copy-status')).toHaveAttribute('role', 'status');
        expect(screen.getByTestId('copy-status')).toHaveTextContent('Username copied');
        expect(username).toHaveFocus();

        await user.click(screen.getByRole('button', { name: 'Copy the incoming mail server' }));
        expect(writeText).toHaveBeenLastCalledWith('imap.example.net');
        await waitFor(() => expect(screen.getByTestId('copy-status')).toHaveTextContent('Incoming mail server copied'));
        expect(screen.getByRole('button', { name: 'Copy the incoming mail server' })).toHaveTextContent('Copied');
        // Only the last one copied says so.
        expect(username).toHaveTextContent('Copy');

        await user.click(screen.getByRole('button', { name: 'Copy the outgoing mail server' }));
        expect(writeText).toHaveBeenLastCalledWith('smtp.example.net');
    });

    it('says so when the browser refuses to copy', async () => {
        renderSettings();
        const user = userEvent.setup();
        vi.spyOn(navigator.clipboard, 'writeText').mockRejectedValue(new Error('denied'));

        await user.click(await screen.findByRole('button', { name: 'Copy the username' }));

        expect(await screen.findByText("Couldn't copy. Select the text and copy it yourself.")).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Copy the username' })).toHaveTextContent('Copy');
        expect(screen.getByTestId('copy-status')).toBeEmptyDOMElement();
    });

    it('leaves the webmail card out when there is no webmail', async () => {
        renderSettings({ client_settings: { ...PORTAL_ME.client_settings, webmail_url: null } });

        expect(await screen.findByRole('heading', { level: 1, name: 'Email app settings' })).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Open webmail' })).not.toBeInTheDocument();
        expect(screen.queryByRole('region', { name: 'Webmail' })).not.toBeInTheDocument();
    });

    it('renders in Finnish', async () => {
        await act(() => setLocale('fi'));
        renderSettings();

        expect(await screen.findByRole('heading', { level: 1, name: 'Sähköpostiohjelman asetukset' })).toBeInTheDocument();
        expect(screen.getByRole('region', { name: 'Saapuva posti (IMAP)' })).toBeInTheDocument();
        expect(screen.getByRole('region', { name: 'Lähtevä posti (SMTP)' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Kopioi käyttäjätunnus' })).toHaveTextContent('Kopioi');
        expect(screen.getByRole('link', { name: 'Avaa verkkoposti' })).toBeInTheDocument();
    });
});
