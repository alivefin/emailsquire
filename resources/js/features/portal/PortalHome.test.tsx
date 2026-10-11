import { beforeEach, describe, expect, it, vi } from 'vitest';
import { act, screen, within } from '@testing-library/react';
import { apiFetch } from '../../api/client';
import { setLocale } from '../../i18n';
import { PORTAL_ME, portalApi } from '../../test/portalServer';
import { renderRoutes } from '../../test/routing';
import type { PortalMe } from './usePortal';
import { PortalHome } from './PortalHome';

vi.mock('../../api/client', () => ({
    apiFetch: vi.fn(),
    onSessionExpired: vi.fn(() => () => {}),
    onTwoFactorRequired: vi.fn(() => () => {}),
    onStepUpRequired: vi.fn(() => () => {}),
    ensureCsrf: vi.fn(),
}));

const routes = [{ path: '/my', element: <PortalHome /> }];
const GIB = 1073741824;

function renderHome(me: Partial<PortalMe> = {}, handlers: Parameters<typeof portalApi>[1] = {}) {
    vi.mocked(apiFetch).mockImplementation(portalApi('mailbox', handlers, { ...PORTAL_ME, ...me }));
    return renderRoutes(routes, '/my');
}

function card(name: string): HTMLElement {
    return screen.getByRole('region', { name });
}

beforeEach(() => {
    vi.mocked(apiFetch).mockReset();
});

describe('PortalHome', () => {
    it('shows the address and the storage used against the quota', async () => {
        renderHome({ address: 'anna@xn--bcher-kva.example', domain_unicode: 'bücher.example', used_bytes: GIB, quota_bytes: 5 * GIB });

        expect(await screen.findByRole('heading', { level: 1, name: 'My mailbox' })).toBeInTheDocument();
        expect(screen.getByText('anna@bücher.example')).toBeInTheDocument();
        const storage = card('Storage');
        const bar = within(storage).getByRole('progressbar', { name: 'Storage used' });
        expect(bar).toHaveAttribute('aria-valuenow', '20');
        expect(bar).toHaveAttribute('data-level', 'ok');
        expect(within(storage).getByText('1 GB of 5 GB used')).toBeInTheDocument();
        expect(within(storage).queryByText(/almost full|is full/)).not.toBeInTheDocument();
    });

    it('says storage use is unavailable while the server does not know it', async () => {
        renderHome({ used_bytes: null, quota_bytes: 5 * GIB });

        const storage = await screen.findByRole('region', { name: 'Storage' });
        const bar = within(storage).getByRole('progressbar', { name: 'Storage used' });
        expect(bar).not.toHaveAttribute('aria-valuenow');
        expect(bar).toHaveAttribute('data-level', 'off');
        expect(within(storage).getByText("Storage use can't be shown right now. Your limit is 5 GB.")).toBeInTheDocument();
    });

    it('warns when the mailbox is almost full, and when it is full', async () => {
        renderHome({ used_bytes: 4.2 * GIB, quota_bytes: 5 * GIB });
        const storage = await screen.findByRole('region', { name: 'Storage' });
        expect(within(storage).getByRole('progressbar')).toHaveAttribute('data-level', 'warn');
        expect(within(storage).getByText(/Your mailbox is almost full\./)).toBeInTheDocument();
    });

    it('warns that new mail may not arrive when the mailbox is full', async () => {
        renderHome({ used_bytes: 5 * GIB, quota_bytes: 5 * GIB });
        const storage = await screen.findByRole('region', { name: 'Storage' });
        expect(within(storage).getByRole('progressbar')).toHaveAttribute('data-level', 'crit');
        expect(within(storage).getByText(/Your mailbox is full\. New mail may not arrive/)).toBeInTheDocument();
    });

    it('summarises forwarding and aliases with links to them', async () => {
        renderHome(
            { self_service: { forwarding: true, aliases: true, external_forwarding: true } },
            {
                '/portal/forwarding': () => ({ targets: ['me@gmail.com', 'bob@example.org'], keep_copy: false }),
                '/portal/aliases': () => ({ data: [{ local: 'info', address: 'info@example.org' }] }),
            },
        );

        const forwarding = await screen.findByRole('region', { name: 'Forwarding' });
        expect(await within(forwarding).findByText('Your mail is forwarded to 2 addresses.')).toBeInTheDocument();
        expect(within(forwarding).getByText('me@gmail.com')).toBeInTheDocument();
        expect(within(forwarding).getByText('bob@example.org')).toBeInTheDocument();
        expect(within(forwarding).getByText('No copy stays in your mailbox.')).toBeInTheDocument();
        expect(within(forwarding).getByRole('link', { name: 'Change forwarding' })).toHaveAttribute('href', '/my/forwarding');
        expect(within(forwarding).queryByText('Your administrator has turned this off.')).not.toBeInTheDocument();

        const aliases = card('Aliases');
        expect(within(aliases).getByText('1 extra address delivers to your mailbox.')).toBeInTheDocument();
        expect(within(aliases).getByText('info@example.org')).toBeInTheDocument();
        expect(within(aliases).getByRole('link', { name: 'Manage aliases' })).toHaveAttribute('href', '/my/aliases');
    });

    it('shows the empty states and says which changes the administrator has turned off', async () => {
        renderHome({ self_service: { forwarding: false, aliases: false, external_forwarding: false } });

        const forwarding = await screen.findByRole('region', { name: 'Forwarding' });
        expect(await within(forwarding).findByText('Your mail is not forwarded.')).toBeInTheDocument();
        expect(within(forwarding).getByText('Your administrator has turned this off.')).toBeInTheDocument();
        expect(within(forwarding).getByRole('link', { name: 'View forwarding' })).toHaveAttribute('href', '/my/forwarding');

        const aliases = card('Aliases');
        expect(await within(aliases).findByText('You have no aliases.')).toBeInTheDocument();
        expect(within(aliases).getByText('Your administrator has turned this off.')).toBeInTheDocument();
        expect(within(aliases).getByRole('link', { name: 'View aliases' })).toHaveAttribute('href', '/my/aliases');
    });

    it('keeps the page when a summary cannot be loaded', async () => {
        renderHome({}, { '/portal/forwarding': () => Promise.reject(new Error('offline')) });

        const forwarding = await screen.findByRole('region', { name: 'Forwarding' });
        expect(await within(forwarding).findByText("This couldn't be loaded. Try again later.")).toBeInTheDocument();
        expect(card('Storage')).toBeInTheDocument();
    });

    it('renders in Finnish', async () => {
        await act(() => setLocale('fi'));
        renderHome(
            { used_bytes: 1.5 * GIB, quota_bytes: 5 * GIB, self_service: { forwarding: true, aliases: false, external_forwarding: false } },
            { '/portal/forwarding': () => ({ targets: ['me@gmail.com'], keep_copy: true }) },
        );

        expect(await screen.findByRole('heading', { level: 1, name: 'Oma postilaatikko' })).toBeInTheDocument();
        expect(within(card('Tallennustila')).getByText('1,5 Gt / 5 Gt käytetty')).toBeInTheDocument();
        const forwarding = card('Edelleenlähetys');
        expect(await within(forwarding).findByText('Postisi lähetetään edelleen 1 osoitteeseen.')).toBeInTheDocument();
        expect(within(forwarding).getByText('Postilaatikkoosi jää kopio.')).toBeInTheDocument();
        expect(within(card('Aliakset')).getByText('Ylläpitäjäsi on poistanut tämän käytöstä.')).toBeInTheDocument();
    });
});
