import { expect, test } from '@playwright/test';
import { rowAction, rowFor, seed, signIn } from './support';

const ADDRESS = 'e2e-box@example.org';

test.beforeAll(() => seed());

test('creates a mailbox with a generated password shown once, then disables and enables it', async ({ page }) => {
    await signIn(page);
    await page.goto('/domains/example.org/mailboxes');
    await page.getByRole('button', { name: /Add mailbox/ }).click();

    const drawer = page.getByRole('dialog', { name: 'New mailbox' });
    await drawer.getByLabel('Address').fill('e2e-box');
    await expect(drawer.getByRole('status')).toBeVisible();
    await drawer.getByRole('button', { name: 'Generate' }).click();
    const password = await drawer.getByLabel('Password', { exact: true }).inputValue();
    expect(password).toHaveLength(16);
    await drawer.getByRole('button', { name: 'Create mailbox' }).click();

    const created = page.getByRole('dialog', { name: 'Mailbox created' });
    await expect(created.getByText('Password — shown once')).toBeVisible();
    await expect(created.getByText(password, { exact: true })).toBeVisible();
    await created.getByRole('button', { name: 'Done' }).click();

    await expect(created).toHaveCount(0);
    await expect(page.getByText(password)).toHaveCount(0);
    expect(await page.content()).not.toContain(password);

    const row = rowFor(page, ADDRESS);
    await expect(row).toContainText('Active');

    await rowAction(page, ADDRESS, 'Disable');
    await expect(row).toContainText('Disabled');

    await rowAction(page, ADDRESS, 'Enable');
    await expect(row).toContainText('Active');
    await expect(row).not.toContainText('Disabled');
});
