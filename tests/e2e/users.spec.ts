import { expect, test } from '@playwright/test';
import {
    acceptAsNewPerson,
    closePersonPages,
    confirmStepUp,
    INVITE_LINK,
    INVITEE_PASSWORD,
    newPersonPage,
    readMailLink,
    seed,
    signIn,
    submitSignIn,
} from './support';

/**
 * The Users screen: inviting a manager from it (pending under the users until accepted), deactivating
 * them (their open page is signed out on its next request and they can't sign in again), and a
 * domain's mailbox limit as "New mailbox" meets it.
 */

const MANAGER = 'manager@example.org';
const NAME = 'Maria Manager';

test.beforeAll(() => seed());
test.afterEach(closePersonPages);

test('a deactivated manager is signed out on their next request and cannot sign in', async ({ page, browser }) => {
    await signIn(page);
    await page.goto('/users');
    await page.getByRole('button', { name: 'Invite user' }).click();

    const invite = page.getByRole('dialog', { name: 'Invite user' });
    await invite.getByLabel('Email', { exact: true }).fill(MANAGER);
    await invite.getByRole('radio', { name: /^Manager/ }).check();
    await invite.getByLabel('Domain', { exact: true }).selectOption('example.org');
    await invite.getByRole('button', { name: 'Send invitation' }).click();
    // Step-up opens a 300 s window for this session: the deactivation below needs no second one.
    await confirmStepUp(page);
    await expect(invite).toHaveCount(0);
    await expect(page.getByText(`Invitation sent to ${MANAGER}.`)).toBeVisible();
    // Until accepted, it waits under the users with Resend and Revoke.
    const pending = page.getByRole('region', { name: 'Pending invitations' });
    await expect(pending.getByRole('listitem').filter({ hasText: MANAGER })).toContainText('Pending');
    await expect(pending.getByRole('button', { name: `Actions for the invitation to ${MANAGER}` })).toBeVisible();

    const manager = await newPersonPage(browser);
    await acceptAsNewPerson(manager, await readMailLink(INVITE_LINK), NAME);
    await expect(manager).toHaveURL('/domains/example.org/mailboxes');
    await expect(manager.getByText('john@example.org').first()).toBeVisible();

    // The admin finds them on the Users screen, no longer pending, and deactivates them.
    await page.goto('/users');
    await expect(page.getByRole('link', { name: NAME })).toBeVisible();
    // The seed's own pending invitation stays listed; the accepted one is gone.
    await expect(pending.getByRole('listitem').filter({ hasText: 'pending@example.org' })).toBeVisible();
    await expect(pending.getByRole('listitem').filter({ hasText: MANAGER })).toHaveCount(0);
    await page.getByRole('link', { name: NAME }).click();
    await expect(page.getByRole('heading', { name: NAME })).toBeVisible();
    await expect(page.getByText('Can sign in.')).toBeVisible();
    await page.getByRole('button', { name: 'Deactivate…' }).click();
    const dialog = page.getByRole('dialog', { name: `Deactivate ${NAME}?` });
    await dialog.getByRole('button', { name: 'Deactivate', exact: true }).click();
    await expect(dialog).toHaveCount(0);
    await expect(page.getByText(`${NAME} was deactivated.`)).toBeVisible();
    await expect(page.getByText("Can't sign in until reactivated.")).toBeVisible();

    // The manager's open page learns it on its next request: the session is gone.
    await manager.getByRole('link', { name: /^Forwarding/ }).click();
    await expect(manager).toHaveURL(/\/signin\?/);
    await expect(manager.getByRole('heading', { name: 'Forwarding' })).toHaveCount(0);

    // Their password no longer signs them in, and the answer is the same as for a wrong one.
    await submitSignIn(manager, INVITEE_PASSWORD, { email: MANAGER });
    await expect(manager.getByRole('alert')).toHaveText(/Email or password is wrong\./);
    await expect(manager).toHaveURL(/\/signin/);
});

test('a mailbox limit at the current count stops "New mailbox" with the limit error', async ({ page }) => {
    await signIn(page);
    await page.goto('/domains/example.org/mailboxes');
    await expect(page.getByText('john@example.org').first()).toBeVisible();

    // Beside the sidebar the header has the button; on a phone it sits in the domain's action menu.
    if ((page.viewportSize()?.width ?? 0) < 768) {
        await page.getByRole('button', { name: 'Domain actions' }).click();
        await page.getByRole('menuitem', { name: 'Domain settings' }).click();
    } else {
        await page.getByRole('button', { name: 'Domain settings' }).click();
    }
    const settings = page.getByRole('dialog', { name: 'Domain settings' });
    // The fixture's example.org has two mailboxes (john and mary).
    await expect(settings.getByText('In use: 2 mailboxes')).toBeVisible();
    await settings.getByLabel('Maximum mailboxes').fill('2');
    await settings.getByRole('button', { name: 'Save' }).click();
    await expect(settings).toHaveCount(0);
    await expect(page.getByText('Limits saved.')).toBeVisible();
    await expect(page.getByText('2 / 2 mailboxes')).toBeVisible();

    await page.getByRole('button', { name: /Add mailbox/ }).click();
    const drawer = page.getByRole('dialog', { name: 'New mailbox' });
    await expect(drawer.getByText('0 mailboxes left')).toBeVisible();
    await drawer.getByLabel('Address').fill('e2e-over');
    await expect(drawer.getByText('e2e-over@example.org is available.')).toBeVisible();
    await drawer.getByRole('button', { name: 'Generate' }).click();
    await drawer.getByRole('button', { name: 'Create mailbox' }).click();

    // The server refuses it (422 domain_limit), and the drawer says why under the address.
    await expect(drawer.getByText('This domain can have at most 2 mailboxes.')).toBeVisible();
    await expect(page.getByRole('dialog', { name: 'Mailbox created' })).toHaveCount(0);
    await drawer.getByRole('button', { name: 'Cancel' }).click();
    await expect(page.getByText('e2e-over@example.org')).toHaveCount(0);
});
