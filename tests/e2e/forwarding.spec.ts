import { expect, test } from '@playwright/test';
import { rowFor, seed, signIn } from './support';

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
