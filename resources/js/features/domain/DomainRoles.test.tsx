import { beforeEach, describe, expect, it, vi } from 'vitest';
import { act, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { apiFetch } from '../../api/client';
import { setLocale } from '../../i18n';
import { useIsPhone } from '../../lib/useIsPhone';
import { routes } from '../../router';
import { fakeServer } from '../../test/domainServer';
import { renderRoutes } from '../../test/routing';

vi.mock('../../api/client', () => ({ apiFetch: vi.fn(), onSessionExpired: vi.fn(() => () => {}), onTwoFactorRequired: vi.fn(() => () => {}), onStepUpRequired: vi.fn(() => () => {}), ensureCsrf: vi.fn() }));
vi.mock('../../lib/useIsPhone', () => ({ useIsPhone: vi.fn(() => false) }));

const GIB = 1073741824;

beforeEach(() => {
    vi.mocked(apiFetch).mockReset();
    vi.mocked(useIsPhone).mockReturnValue(false);
});

describe('a viewer on the domain page', () => {
    it('sees the view note and no write controls on Mailboxes', async () => {
        fakeServer({ role: 'viewer' });
        renderRoutes(routes, '/domains/example.org/mailboxes');

        await screen.findByText('anna@example.org');
        expect(await screen.findByText('You can view this domain')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: '+ Add mailbox' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /^Actions for/ })).not.toBeInTheDocument();
        expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Delete domain…' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Domain settings' })).not.toBeInTheDocument();
    });

    it('opens a mailbox row as a read-only detail', async () => {
        const user = userEvent.setup();
        fakeServer({ role: 'viewer' });
        renderRoutes(routes, '/domains/example.org/mailboxes');

        await user.click(await screen.findByRole('button', { name: /^anna@example\.org/ }));
        const drawer = await screen.findByRole('dialog', { name: 'anna@example.org' });
        expect(await within(drawer).findByText('You can view this mailbox but not change it.')).toBeInTheDocument();
        expect(within(drawer).getByText('Anna Admin')).toBeInTheDocument();
        expect(within(drawer).getByText('Active')).toBeInTheDocument();
        expect(within(drawer).queryByRole('button', { name: 'Generate a new password' })).not.toBeInTheDocument();
        expect(within(drawer).queryByRole('button', { name: 'Save limit' })).not.toBeInTheDocument();
        expect(within(drawer).queryByRole('button', { name: 'Save name' })).not.toBeInTheDocument();
        expect(within(drawer).queryByRole('button', { name: 'Delete mailbox…' })).not.toBeInTheDocument();
        expect(within(drawer).queryByRole('switch')).not.toBeInTheDocument();
        expect(within(drawer).queryByRole('button', { name: '+ Add' })).not.toBeInTheDocument();
        expect(within(drawer).queryByRole('textbox')).not.toBeInTheDocument();
    });

    it('sees no Add on Forwarding, in the toolbar or in the empty state', async () => {
        fakeServer({ role: 'viewer' });
        renderRoutes(routes, '/domains/example.org/forwarding');

        // Drawn only once the role is known, so the absences below can't pass on a role still loading.
        expect(await screen.findByText('You can view this domain')).toBeInTheDocument();
        expect(await screen.findByText('No forwarding addresses in example.org yet')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: '+ Add forwarding address' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Add forwarding address' })).not.toBeInTheDocument();
    });

    it('gets a View button instead of Edit and the row menu on a phone card', async () => {
        vi.mocked(useIsPhone).mockReturnValue(true);
        fakeServer({ role: 'viewer' });
        renderRoutes(routes, '/domains/example.org/mailboxes');

        expect(await screen.findByText('You can view this domain')).toBeInTheDocument();
        expect(await screen.findByRole('button', { name: 'View anna@example.org' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Edit anna@example.org' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Add mailbox' })).not.toBeInTheDocument();
        await user_openMenu();
    });

    it('shows the view note in Finnish', async () => {
        await setLocale('fi');
        fakeServer({ role: 'viewer' });
        renderRoutes(routes, '/domains/example.org/mailboxes');
        expect(await screen.findByText('Voit katsella tätä verkkotunnusta')).toBeInTheDocument();
    });
});

/** The phone's domain menu keeps Pin but has no delete for a viewer. */
async function user_openMenu() {
    const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: 'Domain actions' }));
    expect(await screen.findByRole('menuitem', { name: 'Pin' })).toBeInTheDocument();
    expect(screen.queryByRole('menuitem', { name: 'Delete domain…' })).not.toBeInTheDocument();
    expect(screen.queryByRole('menuitem', { name: 'Domain settings' })).not.toBeInTheDocument();
}

describe('a manager on the domain page', () => {
    it('sees the writes but not Delete domain or Domain settings, and no view note', async () => {
        fakeServer({ role: 'manager' });
        renderRoutes(routes, '/domains/example.org/mailboxes');

        expect(await screen.findByRole('button', { name: '+ Add mailbox' })).toBeInTheDocument();
        expect(screen.getAllByRole('button', { name: /^Actions for/ })).toHaveLength(3);
        expect(screen.getAllByRole('checkbox').length).toBeGreaterThan(0);
        expect(screen.queryByRole('button', { name: 'Delete domain…' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Domain settings' })).not.toBeInTheDocument();
        expect(screen.queryByText('You can view this domain')).not.toBeInTheDocument();
    });

    it('sees the limits in the header when one is set', async () => {
        fakeServer({
            role: 'manager',
            rows: { mailbox_count: 12, max_mailboxes: 20, quota_bytes_allocated: 48 * GIB, max_quota_bytes_total: 100 * GIB },
        });
        renderRoutes(routes, '/domains/example.org/mailboxes');
        expect(await screen.findByText('12 / 20 mailboxes · 48 / 100 GB allocated')).toBeInTheDocument();
    });

    it('shows only the limit that is set', async () => {
        fakeServer({ role: 'manager', rows: { mailbox_count: 3, max_mailboxes: 5 } });
        renderRoutes(routes, '/domains/example.org/mailboxes');
        expect(await screen.findByText('3 / 5 mailboxes')).toBeInTheDocument();
    });

    it('shows no usage text when nothing is limited', async () => {
        fakeServer({ role: 'manager' });
        renderRoutes(routes, '/domains/example.org/mailboxes');
        // The Forwarding count comes from the same domain row as the limits, and Add from the role.
        await screen.findByRole('link', { name: 'Forwarding 2' });
        await screen.findByRole('button', { name: '+ Add mailbox' });
        expect(screen.queryByText(/allocated/)).not.toBeInTheDocument();
        expect(screen.queryByText(/ \/ \d+ mailbox/)).not.toBeInTheDocument();
    });
});

describe('an admin on the domain page', () => {
    it('opens Domain settings from the header', async () => {
        const user = userEvent.setup();
        fakeServer();
        renderRoutes(routes, '/domains/example.org/mailboxes');
        await user.click(await screen.findByRole('button', { name: 'Domain settings' }));
        expect(await screen.findByRole('dialog', { name: 'Domain settings' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Delete domain…' })).toBeInTheDocument();
    });

    it('has Domain settings in the phone menu', async () => {
        const user = userEvent.setup();
        vi.mocked(useIsPhone).mockReturnValue(true);
        fakeServer();
        renderRoutes(routes, '/domains/example.org/mailboxes');
        await screen.findByText('anna@example.org');
        await user.click(screen.getByRole('button', { name: 'Domain actions' }));
        await user.click(await screen.findByRole('menuitem', { name: 'Domain settings' }));
        await waitFor(() => expect(screen.getByRole('dialog', { name: 'Domain settings' })).toBeInTheDocument());
    });
});

describe('a stale role', () => {
    it('shows the server refusal when a write the role no longer allows is tried', async () => {
        const user = userEvent.setup();
        const server = fakeServer({ role: 'manager' });
        const { ApiProblem } = await import('../../api/problems');
        server.write = () => Promise.reject(new ApiProblem({ status: 403, title: 'Forbidden', code: 'forbidden' }));
        renderRoutes(routes, '/domains/example.org/mailboxes');

        await user.click(await screen.findByRole('button', { name: 'Actions for sales@example.org' }));
        await act(async () => {
            await user.click(await screen.findByRole('menuitem', { name: 'Enable' }));
        });
        expect(await screen.findByText("You don't have permission to do that.")).toBeInTheDocument();
    });
});
