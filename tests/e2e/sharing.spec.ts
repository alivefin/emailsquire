import { expect, type Page, test } from '@playwright/test';
import { acceptAsNewPerson, closePersonPages, newPersonPage, rowFor, seed, shareDomain, signIn } from './support';

/**
 * Sharing a domain: the admin invites a manager and a viewer of example.org from its Access tab, each
 * accepts the emailed link as a new person, and then sees example.org only (seed.php adds example.net,
 * with two mailboxes, beside it). The Finnish invitation runs in finnish.spec.
 */

const MANAGER = 'manager@example.org';
const VIEWER = 'viewer@example.org';

test.beforeAll(() => seed());
test.afterEach(closePersonPages);

/** The navigation column: beside the page from 768 px, behind the Menu button on a phone. */
async function openSidebar(page: Page) {
    if ((page.viewportSize()?.width ?? 0) < 768) {
        await page.getByRole('button', { name: 'Menu', exact: true }).click();
        return page.getByRole('dialog', { name: 'Navigation' }).getByRole('navigation', { name: 'Main', exact: true });
    }
    return page.getByRole('navigation', { name: 'Main', exact: true });
}

test('a manager invited to example.org accepts, creates a mailbox and sees nothing of example.net', async ({ page, browser }) => {
    await signIn(page);
    const link = await shareDomain(page, { domain: 'example.org', email: MANAGER, role: 'manager' });

    // The Access tab lists the invitation until it is accepted.
    // The seed has its own pending invitation, so look for "Pending" inside the manager's row only.
    const invitationRow = page.locator('tr, li, div').filter({ hasText: MANAGER }).filter({ hasText: 'Pending' }).last();
    await expect(invitationRow).toBeVisible();

    const manager = await newPersonPage(browser);
    await acceptAsNewPerson(manager, link, 'Maria Manager');

    // One domain and no admin role: the console opens on that domain's mailboxes.
    await expect(manager).toHaveURL('/domains/example.org/mailboxes');
    await expect(manager.getByText('Welcome to EmailSquire.')).toBeVisible();
    await expect(manager.getByText('john@example.org').first()).toBeVisible();

    // A manager can add a mailbox.
    await manager.getByRole('button', { name: /Add mailbox/ }).click();
    const drawer = manager.getByRole('dialog', { name: 'New mailbox' });
    await drawer.getByLabel('Address', { exact: true }).fill('e2e-managed');
    await expect(drawer.getByRole('status')).toBeVisible();
    await drawer.getByRole('button', { name: 'Generate' }).click();
    await drawer.getByRole('button', { name: 'Create mailbox' }).click();
    const created = manager.getByRole('dialog', { name: 'Mailbox created' });
    await expect(created).toContainText('e2e-managed@example.org');
    await created.getByRole('button', { name: 'Done' }).click();
    await expect(rowFor(manager, 'e2e-managed@example.org')).toContainText('Active');

    // No domain settings, no deleting the domain.
    await expect(manager.getByRole('button', { name: 'Domain settings' })).toHaveCount(0);
    await expect(manager.getByRole('button', { name: 'Delete domain' })).toHaveCount(0);

    // example.net is nowhere: not in the navigation's domain count, the domain list or a search…
    const nav = await openSidebar(manager);
    await expect(nav.getByTitle('1 domain', { exact: true })).toBeVisible();
    await expect(nav.getByRole('link', { name: /^Users/ })).toHaveCount(0);
    await expect(nav).not.toContainText('example.net');
    await manager.keyboard.press('Escape');

    await manager.goto('/domains');
    await expect(manager.getByRole('link', { name: 'example.org', exact: true })).toBeVisible();
    await expect(manager.getByText('example.net')).toHaveCount(0);

    // The header's jump box (a second copy waits, hidden, for narrower widths).
    const jump = manager.getByRole('searchbox', { name: 'Jump to domain or address' }).filter({ visible: true });
    await jump.fill('example.net');
    await jump.press('Enter');
    await expect(manager).toHaveURL('/addresses?q=example.net');
    await expect(manager.getByText('Nothing matches “example.net”')).toBeVisible();
    await expect(manager.getByText('alice@example.net')).toHaveCount(0);

    // …and its page is not there for them, nor is the Users screen.
    await manager.goto('/domains/example.net/mailboxes');
    await expect(manager.getByRole('heading', { name: 'example.net is not on this server.' })).toBeVisible();
    await expect(manager.getByText('alice@example.net')).toHaveCount(0);
    await manager.goto('/users');
    await expect(manager.getByRole('heading', { name: 'Page not found', level: 1 })).toBeVisible();

    // The admin's Access tab now shows them as an active manager.
    await page.reload();
    await expect(page.getByText('Maria Manager')).toBeVisible();
    await expect(page.getByText(MANAGER)).toBeVisible();
});

test('a viewer invited to example.org sees its mailboxes and forwarding but no control that changes them', async ({ page, browser }) => {
    await signIn(page);
    const link = await shareDomain(page, { domain: 'example.org', email: VIEWER, role: 'viewer' });

    const viewer = await newPersonPage(browser);
    await acceptAsNewPerson(viewer, link, 'Ville Viewer');

    await expect(viewer).toHaveURL('/domains/example.org/mailboxes');
    await expect(viewer.getByText('You can view this domain')).toBeVisible();
    await expect(viewer.getByText('john@example.org').first()).toBeVisible();
    await expect(viewer.getByRole('button', { name: /Add mailbox/ })).toHaveCount(0);
    await expect(viewer.getByRole('button', { name: 'Actions for john@example.org' })).toHaveCount(0);
    await expect(viewer.getByRole('button', { name: 'Edit john@example.org' })).toHaveCount(0);
    await expect(viewer.getByRole('checkbox')).toHaveCount(0);
    await expect(viewer.getByRole('button', { name: 'Domain settings' })).toHaveCount(0);

    await viewer.goto('/domains/example.org/forwarding');
    await expect(viewer.getByText('jack@example.org').first()).toBeVisible();
    await expect(viewer.getByRole('button', { name: /Add forwarding address/ })).toHaveCount(0);
    await expect(viewer.getByRole('button', { name: 'Actions for jack@example.org' })).toHaveCount(0);

    // They see who else has access, but can't share it or change anyone's role.
    await viewer.goto('/domains/example.org/access');
    await expect(viewer.getByText('Ville Viewer')).toBeVisible();
    await expect(viewer.getByRole('button', { name: 'Share access' })).toHaveCount(0);
    await expect(viewer.getByRole('combobox', { name: /^Role of / })).toHaveCount(0);

    await viewer.goto('/domains/example.net/mailboxes');
    await expect(viewer.getByRole('heading', { name: 'example.net is not on this server.' })).toBeVisible();
});
