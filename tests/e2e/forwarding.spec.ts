import { expect, test } from '@playwright/test';
import { rowAction, rowFor, seed, setCatchAll, signIn } from './support';

test.beforeAll(() => seed());

test('forwards an existing mailbox to an external address and keeps a copy', async ({ page }) => {
    await signIn(page);
    await page.goto('/domains/example.org/forwarding');
    await page.getByRole('button', { name: /Add forwarding address/ }).click();

    const drawer = page.getByRole('dialog', { name: 'New forwarding address' });
    await drawer.getByLabel('Address that receives the mail').fill('john');
    await expect(drawer.getByRole('status')).toContainText('Its mail is forwarded as well.');

    const target = drawer.getByLabel('Deliver to');
    await target.fill('me+tag@gmail.com');
    await target.press('Enter');
    await expect(drawer.getByText('me+tag@gmail.com', { exact: true })).toBeVisible();

    const keep = drawer.getByRole('switch', { name: 'Keep a copy in the mailbox' });
    await expect(keep).toHaveAttribute('aria-checked', 'true');

    await drawer.getByRole('button', { name: 'Add forwarding address', exact: true }).click();
    await expect(drawer).toHaveCount(0);

    const row = rowFor(page, 'john@example.org', 'me+tag@gmail.com');
    await expect(row).toContainText('keeps a copy');
});

test('sets catch-all forwarding to a mailbox, changes its destinations and removes it', async ({ page }) => {
    await signIn(page);
    // The e2e main.cf names the installer's email2email map, so the server supports a catch-all.
    await setCatchAll(page, 'example.org', ['john@example.org']);

    const catchAll = 'Catch-all @example.org';
    const row = rowFor(page, catchAll, 'john@example.org');
    await expect(row).toContainText('Catch-all · mail to any other address');
    await expect(page.getByRole('button', { name: '+ Set catch-all forwarding' })).toHaveCount(0);

    await rowAction(page, catchAll, 'Edit destinations');
    const drawer = page.getByRole('dialog', { name: 'Catch-all forwarding' });
    // A catch-all has no mailbox of its own, so there is no copy to keep.
    await expect(drawer.getByRole('switch', { name: 'Keep a copy in the mailbox' })).toHaveCount(0);
    const target = drawer.getByLabel('Deliver to');
    await target.fill('mary@example.org');
    await target.press('Enter');
    await drawer.getByRole('button', { name: 'Save destinations' }).click();
    await expect(drawer).toHaveCount(0);
    await expect(rowFor(page, catchAll, 'mary@example.org')).toContainText('john@example.org');

    await rowAction(page, catchAll, 'Remove');
    await expect(page.getByText('Removed catch-all forwarding for example.org.')).toBeVisible();
    await expect(page.getByRole('button', { name: `Actions for ${catchAll}`, exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: '+ Set catch-all forwarding' })).toBeVisible();
});
