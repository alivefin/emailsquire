import { expect, test } from '@playwright/test';
import { rowAction, seed, signIn } from './support';

const DOMAIN = 'e2e-delete.example';

test.beforeAll(() => seed());

test('adds a domain and deletes it with typed confirmation', async ({ page }) => {
    await signIn(page);
    await page.goto('/domains');

    await page.getByRole('button', { name: '+ Add domain' }).click();
    await page.getByLabel('Domain name').fill(DOMAIN);
    await page.getByRole('button', { name: 'Add domain', exact: true }).click();
    await expect(page).toHaveURL(`/domains/${DOMAIN}/mailboxes`);

    await page.goto('/domains');
    await expect(page.getByRole('link', { name: DOMAIN })).toBeVisible();
    await rowAction(page, DOMAIN, 'Delete…');

    const dialog = page.getByRole('dialog', { name: `Delete ${DOMAIN}?` });
    const confirm = dialog.getByRole('button', { name: 'Delete domain' });
    await expect(confirm).toBeDisabled();
    await dialog.getByLabel(`Type ${DOMAIN} to confirm`).fill(DOMAIN);
    await confirm.click();

    await expect(dialog).toHaveCount(0);
    await expect(page.getByRole('link', { name: DOMAIN })).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'example.org', exact: true })).toBeVisible();
});
