import { beforeEach, describe, expect, it, vi } from 'vitest';
import { act, screen, within } from '@testing-library/react';
import { apiFetch } from '../../api/client';
import { setLocale } from '../../i18n';
import { PORTAL_ME, portalApi } from '../../test/portalServer';
import { renderRoutes } from '../../test/routing';
import type { PortalMe } from './usePortal';
import { PortalHelp } from './PortalHelp';

vi.mock('../../api/client', () => ({
    apiFetch: vi.fn(),
    onSessionExpired: vi.fn(() => () => {}),
    onTwoFactorRequired: vi.fn(() => () => {}),
    onStepUpRequired: vi.fn(() => () => {}),
    ensureCsrf: vi.fn(),
}));

const routes = [{ path: '/my/help', element: <PortalHelp /> }];

function renderHelp(me: Partial<PortalMe> = {}) {
    vi.mocked(apiFetch).mockImplementation(portalApi('mailbox', {}, { ...PORTAL_ME, ...me }));
    return renderRoutes(routes, '/my/help');
}

beforeEach(() => {
    vi.mocked(apiFetch).mockReset();
});

describe('PortalHelp', () => {
    it("lists the domain's managers with a mail link each", async () => {
        renderHelp({
            managers: [
                { name: 'Mia Manager', email: 'mia@example.net' },
                { name: 'Olli Owner', email: 'olli@example.org' },
            ],
            support_contact: 'Call 555 0100',
        });

        expect(await screen.findByRole('heading', { level: 1, name: 'Help and contact' })).toBeInTheDocument();
        expect(screen.getByText("Your domain's managers can help you:")).toBeInTheDocument();
        const list = screen.getByRole('list', { name: 'Who to contact' });
        const items = within(list).getAllByRole('listitem');
        expect(items).toHaveLength(2);
        expect(within(items[0]!).getByText('Mia Manager')).toBeInTheDocument();
        expect(within(items[0]!).getByRole('link', { name: 'mia@example.net' })).toHaveAttribute('href', 'mailto:mia@example.net');
        expect(within(items[1]!).getByRole('link', { name: 'olli@example.org' })).toHaveAttribute('href', 'mailto:olli@example.org');
        // The managers win: the support contact is the fallback only.
        expect(screen.queryByText('Call 555 0100')).not.toBeInTheDocument();
        expect(screen.getByText("Forgot your password? You can't reset it yourself. Ask your domain's manager to set a new one.")).toBeInTheDocument();
    });

    it('shows an address that would add to a mail link as plain text, not as a link', async () => {
        renderHelp({
            managers: [
                { name: 'Eve', email: 'eve@example.org?cc=boss@example.net' },
                { name: 'Pat', email: 'pat%40x@example.org' },
                { name: 'Sam', email: 'sam smith@example.org' },
                { name: 'Mia Manager', email: 'mia@example.net' },
            ],
        });

        expect(await screen.findByText('eve@example.org?cc=boss@example.net')).toBeInTheDocument();
        const links = within(screen.getByRole('list', { name: 'Who to contact' })).getAllByRole('link');
        expect(links.map((a) => a.getAttribute('href'))).toEqual(['mailto:mia@example.net']);
        expect(screen.getByText('pat%40x@example.org').closest('a')).toBeNull();
        expect(screen.getByText('sam smith@example.org').closest('a')).toBeNull();
    });

    it('shows an address with a list separator, angle bracket or quote as plain text', async () => {
        const odd = ['a@example.org,b@example.net', 'a@example.org;b@example.net', 'Boss <boss@example.net>', '"x"@example.org'];
        renderHelp({
            managers: [...odd.map((email, i) => ({ name: `Odd ${i}`, email })), { name: 'Mia Manager', email: 'mia@example.net' }],
        });

        const list = await screen.findByRole('list', { name: 'Who to contact' });
        expect(within(list).getAllByRole('link').map((a) => a.getAttribute('href'))).toEqual(['mailto:mia@example.net']);
        for (const email of odd) expect(within(list).getByText(email).closest('a')).toBeNull();
    });

    it('uses the singular for one manager', async () => {
        renderHelp();
        expect(await screen.findByText("Your domain's manager can help you:")).toBeInTheDocument();
    });

    it('shows the support contact as plain text when the domain has no managers', async () => {
        renderHelp({ managers: [], support_contact: 'IT desk, room 4\nhttps://help.example.net <b>' });

        const contact = await screen.findByTestId('support-contact');
        expect(contact).toHaveTextContent('IT desk, room 4 https://help.example.net <b>');
        expect(contact.querySelector('a, b')).toBeNull();
        expect(screen.getByText('For help, contact:')).toBeInTheDocument();
        expect(screen.queryByRole('list', { name: 'Who to contact' })).not.toBeInTheDocument();
    });

    it('falls back to a general line when there is nobody to name', async () => {
        renderHelp({ managers: [], support_contact: null });

        expect(
            await screen.findByText('For help, contact the person or company that set up your email.'),
        ).toBeInTheDocument();
        expect(screen.queryByTestId('support-contact')).not.toBeInTheDocument();
        expect(screen.getByText("Forgot your password? You can't reset it yourself. Ask your administrator to set a new one.")).toBeInTheDocument();
    });

    it('renders in Finnish', async () => {
        await act(() => setLocale('fi'));
        renderHelp();

        expect(await screen.findByRole('heading', { level: 1, name: 'Ohjeet ja yhteystiedot' })).toBeInTheDocument();
        expect(screen.getByText('Verkkotunnuksesi hallinnoija auttaa sinua:')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'mia@example.net' })).toBeInTheDocument();
    });
});
