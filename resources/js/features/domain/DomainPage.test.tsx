import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { apiFetch } from '../../api/client';
import { useIsPhone } from '../../lib/useIsPhone';
import { routes } from '../../router';
import { fakeServer, writes } from '../../test/domainServer';
import { renderRoutes } from '../../test/routing';

vi.mock('../../api/client', () => ({ apiFetch: vi.fn(), onSessionExpired: vi.fn(() => () => {}), onTwoFactorRequired: vi.fn(() => () => {}), onStepUpRequired: vi.fn(() => () => {}), ensureCsrf: vi.fn() }));
vi.mock('../../lib/useIsPhone', () => ({ useIsPhone: vi.fn(() => false) }));

beforeEach(() => {
    vi.mocked(apiFetch).mockReset();
    vi.mocked(useIsPhone).mockReturnValue(false);
});

/** The desktop header, once RequireAuth has let the layout render. */
function header() {
    return screen.findByRole('banner');
}

describe('DomainPage header', () => {
    it('shows the unicode name in the switcher with the position, and the punycode name in the URL', async () => {
        fakeServer();
        renderRoutes(routes, '/domains/xn--bcher-kva.example/mailboxes');
        const select = await within(await header()).findByRole('combobox', { name: 'Domain' });
        await waitFor(() => expect(select).toHaveValue('xn--bcher-kva.example'));
        expect(within(select).getByRole('option', { name: 'bücher.example' })).toBeInTheDocument();
        expect(within(await header()).getByText('2 / 3')).toBeInTheDocument();
    });

    it('wraps › on the last domain to the first and keeps the tab', async () => {
        const user = userEvent.setup();
        fakeServer();
        const { router } = renderRoutes(routes, '/domains/zeta.example/forwarding');
        await within(await header()).findByText('3 / 3');
        await user.click(within(await header()).getByRole('button', { name: 'Next domain' }));
        await waitFor(() => expect(router.state.location.pathname).toBe('/domains/example.org/forwarding'));
    });

    it('wraps ‹ on the first domain to the last', async () => {
        const user = userEvent.setup();
        fakeServer();
        const { router } = renderRoutes(routes, '/domains/example.org/mailboxes');
        await within(await header()).findByText('1 / 3');
        await user.click(within(await header()).getByRole('button', { name: 'Previous domain' }));
        await waitFor(() => expect(router.state.location.pathname).toBe('/domains/zeta.example/mailboxes'));
    });

    it('switches domain from the select', async () => {
        const user = userEvent.setup();
        fakeServer();
        const { router } = renderRoutes(routes, '/domains/example.org/mailboxes?q=an');
        const select = await within(await header()).findByRole('combobox', { name: 'Domain' });
        await user.selectOptions(select, 'bücher.example');
        await waitFor(() => expect(router.state.location.pathname).toBe('/domains/xn--bcher-kva.example/mailboxes'));
        expect(router.state.location.search).toBe('');
    });

    it('pins with PUT and unpins with DELETE', async () => {
        const user = userEvent.setup();
        fakeServer();
        renderRoutes(routes, '/domains/xn--bcher-kva.example/mailboxes');
        await user.click(await within(await header()).findByRole('button', { name: 'Pin' }));
        await user.click(await within(await header()).findByRole('button', { name: 'Pinned' }));
        await within(await header()).findByRole('button', { name: 'Pin' });
        expect(writes()).toEqual([
            ['/me/pinned-domains/xn--bcher-kva.example', { method: 'PUT' }],
            ['/me/pinned-domains/xn--bcher-kva.example', { method: 'DELETE' }],
        ]);
    });

    it('opens the delete-domain dialog with the counts and goes to /domains after deleting', async () => {
        const user = userEvent.setup();
        fakeServer();
        const { router } = renderRoutes(routes, '/domains/xn--bcher-kva.example/mailboxes');
        await user.click(await screen.findByRole('button', { name: 'Delete domain…' }));
        const dialog = await screen.findByRole('dialog');
        expect(dialog).toHaveTextContent('Delete bücher.example?');
        expect(dialog).toHaveTextContent('1 mailbox and 2 forwarding addresses will be deleted.');
        await user.type(within(dialog).getByLabelText(/Type .* to confirm/), 'bücher.example');
        await user.click(within(dialog).getByRole('button', { name: 'Delete domain' }));
        await waitFor(() => expect(router.state.location.pathname).toBe('/domains'));
    });

    it('says so when the domain is not on this server', async () => {
        fakeServer();
        renderRoutes(routes, '/domains/nope.example/mailboxes');
        expect(await screen.findByText('nope.example is not on this server.')).toBeInTheDocument();
    });

    it('shows the Mailboxes and Forwarding tabs with their counts', async () => {
        fakeServer();
        renderRoutes(routes, '/domains/example.org/mailboxes');
        const tabs = await screen.findByRole('navigation', { name: 'Domain sections' });
        expect(await within(tabs).findByRole('link', { name: 'Mailboxes 3' })).toHaveAttribute('aria-current', 'page');
        expect(await within(tabs).findByRole('link', { name: 'Forwarding 2' })).toHaveAttribute(
            'href',
            '/domains/example.org/forwarding',
        );
    });

    it('shows the DNS tab to every role and keeps it when switching domain', async () => {
        const user = userEvent.setup();
        fakeServer({ role: 'viewer' });
        const { router } = renderRoutes(routes, '/domains/example.org/mailboxes');
        const tabs = await screen.findByRole('navigation', { name: 'Domain sections' });
        await user.click(await within(tabs).findByRole('link', { name: 'DNS' }));
        await waitFor(() => expect(router.state.location.pathname).toBe('/domains/example.org/dns'));
        expect(await screen.findByText('The first DNS check is running. This takes up to a minute.')).toBeInTheDocument();
        await user.click(within(await header()).getByRole('button', { name: 'Next domain' }));
        await waitFor(() => expect(router.state.location.pathname).toBe('/domains/xn--bcher-kva.example/dns'));
    });

    it('opens the new mailbox drawer for this domain from Add mailbox', async () => {
        const user = userEvent.setup();
        fakeServer();
        renderRoutes(routes, '/domains/xn--bcher-kva.example/mailboxes');
        await user.click(await screen.findByRole('button', { name: '+ Add mailbox' }));
        const drawer = screen.getByRole('dialog', { name: 'New mailbox' });
        expect(within(drawer).queryByRole('combobox', { name: 'Domain' })).not.toBeInTheDocument();
        expect(await within(drawer).findByText('@bücher.example')).toBeInTheDocument();
        await user.click(within(drawer).getByRole('button', { name: 'Cancel' }));
        expect(screen.queryByRole('dialog', { name: 'New mailbox' })).not.toBeInTheDocument();
    });

    it('opens the edit drawer for a mailbox row, and for Reset password with the button focused', async () => {
        const user = userEvent.setup();
        fakeServer();
        renderRoutes(routes, '/domains/example.org/mailboxes');
        await user.click(await screen.findByRole('button', { name: /^anna@example\.org/ }));
        const drawer = await screen.findByRole('dialog', { name: 'anna@example.org' });
        expect(await within(drawer).findByText('Anna Admin · Domains / example.org / Mailboxes')).toBeInTheDocument();
        await user.click(within(drawer).getByRole('button', { name: 'Close' }));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: 'Actions for bob@example.org' }));
        await user.click(screen.getByRole('menuitem', { name: 'Reset password' }));
        const bob = await screen.findByRole('dialog', { name: 'bob@example.org' });
        await waitFor(() => expect(within(bob).getByRole('button', { name: 'Generate a new password' })).toHaveFocus());
    });
});

describe('DomainPage on a phone', () => {
    it('puts the unicode name and the arrows in the top bar, and pin and delete in a menu', async () => {
        const user = userEvent.setup();
        vi.mocked(useIsPhone).mockReturnValue(true);
        fakeServer();
        const { router } = renderRoutes(routes, '/domains/xn--bcher-kva.example/mailboxes');
        const bar = await screen.findByRole('banner');
        expect(await within(bar).findByText('bücher.example')).toBeInTheDocument();
        expect(within(bar).queryByRole('combobox')).not.toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: 'Domain actions' }));
        await user.click(await screen.findByRole('menuitem', { name: 'Pin' }));
        await waitFor(() =>
            expect(writes()).toEqual([['/me/pinned-domains/xn--bcher-kva.example', { method: 'PUT' }]]),
        );

        await user.click(within(bar).getByRole('button', { name: 'Next domain' }));
        await waitFor(() => expect(router.state.location.pathname).toBe('/domains/zeta.example/mailboxes'));
    });
});
