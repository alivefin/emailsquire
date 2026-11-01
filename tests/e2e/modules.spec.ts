import { expect, type Page, test } from '@playwright/test';
import { closePersonPages, type Locale, seed, signIn, signInFinnish, signInOwner } from './support';

/**
 * The module platform with the fixture module (tests/fixtures/modules/example, built by
 * `npm run build:fixture-module` and linked into public/modules): a server admin gets its sidebar item,
 * page and dashboard card in either language; a mailbox owner gets none of it.
 */

test.beforeAll(() => seed());
test.afterEach(closePersonPages);

/** The navigation column: beside the page from 768 px, behind the menu button on a phone. */
async function openSidebar(page: Page, locale: Locale = 'en') {
    if ((page.viewportSize()?.width ?? 0) < 768) {
        await page.getByRole('button', { name: locale === 'fi' ? 'Valikko' : 'Menu', exact: true }).click();
        return page.getByRole('dialog', { name: locale === 'fi' ? 'Navigointi' : 'Navigation' }).getByRole('navigation');
    }
    return page.getByRole('navigation', { name: locale === 'fi' ? 'Päänavigointi' : 'Main', exact: true });
}

test('an admin opens the example module from the sidebar and sees its page', async ({ page }) => {
    await signIn(page);

    const sidebar = await openSidebar(page);
    await sidebar.getByRole('link', { name: 'Notes', exact: true }).click();

    await expect(page).toHaveURL('/example');
    await expect(page.getByRole('heading', { name: 'Example notes' })).toBeVisible();
});

test('the dashboard shows the module card', async ({ page }) => {
    await signIn(page);
    await expect(page.getByText('Example summary')).toBeVisible();
});

test('the module speaks Finnish to an admin who chose Finnish', async ({ page }) => {
    await signInFinnish(page);
    await expect(page.getByText('Esimerkkiyhteenveto')).toBeVisible();

    const sidebar = await openSidebar(page, 'fi');
    await sidebar.getByRole('link', { name: 'Muistiinpanot', exact: true }).click();

    await expect(page).toHaveURL('/example');
    await expect(page.getByRole('heading', { name: 'Esimerkkimuistiinpanot' })).toBeVisible();
});

test('a mailbox owner who visits /example lands in the portal with no module UI', async ({ page }) => {
    await signInOwner(page);
    await page.goto('/example');

    await expect(page).toHaveURL('/my');
    await expect(page.getByRole('heading', { level: 1, name: 'My mailbox' })).toBeVisible();
    await expect(page.getByText('Example notes')).toHaveCount(0);
    await expect(page.getByText('Example summary')).toHaveCount(0);
});
