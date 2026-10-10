import { expect, type Page, test } from '@playwright/test';
import { checkLayout, expectNoCspConsoleErrors, FI_ADMIN, MAIN_ROUTES, seed, submitSignIn, useLocale, watchCsp } from './support';

/**
 * The console in Finnish: the language picked before sign-in reaches the server's messages, the
 * language saved on the user wins after sign-in, and the longer Finnish text still fits every width.
 */

const ADDRESS = 'e2e-fi@example.org';

test.beforeAll(() => seed());

/** Signs in as the Finnish admin from a Finnish sign-in page and waits for the Finnish dashboard. */
async function signInFinnish(page: Page): Promise<void> {
    await useLocale(page, 'fi');
    await page.goto('/signin');
    await submitSignIn(page, FI_ADMIN.password, { email: FI_ADMIN.email, locale: 'fi' });
    await expect(page).toHaveURL('/');
    await expect(page.getByRole('heading', { level: 1, name: 'Yleisnäkymä' })).toBeVisible();
}

test('a guest who picks Finnish gets the sign-in failure in Finnish', async ({ page }) => {
    await page.goto('/signin');
    await expect(page.getByRole('button', { name: 'Continue' })).toBeVisible();

    await page.getByRole('button', { name: 'Suomeksi' }).click();
    await expect(page.getByRole('button', { name: 'Jatka' })).toBeVisible();
    await expect(page.locator('html')).toHaveAttribute('lang', 'fi');

    await submitSignIn(page, 'not-the-password', { locale: 'fi' });

    await expect(page.getByRole('alert')).toHaveText(/Sähköpostiosoite tai salasana on väärin\./);
    await expect(page).toHaveURL('/signin');
});

test('the admin who chose Finnish gets a Finnish dashboard from an English sign-in page', async ({ page }) => {
    await page.goto('/signin');
    await submitSignIn(page, FI_ADMIN.password, { email: FI_ADMIN.email });

    await expect(page).toHaveURL('/');
    await expect(page.getByRole('heading', { level: 1, name: 'Yleisnäkymä' })).toBeVisible();
    await expect(page.getByText('Viimeaikainen toiminta')).toBeVisible();
    await expect(page.locator('html')).toHaveAttribute('lang', 'fi');
    await expect(page.getByRole('heading', { level: 1, name: 'Dashboard' })).toHaveCount(0);
});

test('creating a mailbox shows the Finnish shown-once password panel', async ({ page }) => {
    await signInFinnish(page);
    await page.goto('/domains/example.org/mailboxes');
    await page.getByRole('button', { name: /Lisää postilaatikko/ }).click();

    const drawer = page.getByRole('dialog', { name: 'Uusi postilaatikko' });
    await drawer.getByLabel('Osoite').fill('e2e-fi');
    await expect(drawer.getByRole('status')).toBeVisible();
    await drawer.getByRole('button', { name: 'Luo', exact: true }).click();
    const password = await drawer.getByLabel('Salasana', { exact: true }).inputValue();
    expect(password).toHaveLength(16);
    await drawer.getByRole('button', { name: 'Luo postilaatikko' }).click();

    const created = page.getByRole('dialog', { name: 'Postilaatikko luotu' });
    await expect(created.getByText('Salasana — näytetään vain kerran')).toBeVisible();
    await expect(created.getByText(password, { exact: true })).toBeVisible();
    await expect(created).toContainText(ADDRESS);
    await created.getByRole('button', { name: 'Valmis' }).click();

    await expect(created).toHaveCount(0);
    expect(await page.content()).not.toContain(password);

    // The dashboard words the admin's own action in Finnish ("Sinä loit", not "You loi").
    await page.goto('/');
    await expect(page.getByRole('listitem').filter({ hasText: `Sinä loit postilaatikon ${ADDRESS}` })).toBeVisible();
});

test('every main screen fits the width in Finnish and breaks no CSP rule', async ({ page }) => {
    const consoleErrors = await watchCsp(page);

    await signInFinnish(page);

    for (const route of MAIN_ROUTES) {
        await test.step(route, () => checkLayout(page, route, 'Verkkotunnus'));
    }

    expectNoCspConsoleErrors(consoleErrors);
});
