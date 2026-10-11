import { expect, test } from '@playwright/test';
import {
    acceptAsNewPerson,
    closePersonPages,
    checkLayout,
    confirmStepUp,
    expectNoCspConsoleErrors,
    FI_ADMIN,
    INVITE_LINK,
    MAIN_ROUTES,
    newPersonPage,
    NOFACTOR_ADMIN,
    PENDING_INVITATION_TOKEN,
    readMailLink,
    seed,
    signInFinnish,
    submitCode,
    submitSignIn,
    useLocale,
    watchCsp,
} from './support';

/**
 * The console in Finnish: the language picked before sign-in reaches the server's messages, the
 * language saved on the user wins after sign-in, and the longer Finnish text still fits every width.
 */

const ADDRESS = 'e2e-fi@example.org';
const INVITEE = 'katselija@example.org';

test.beforeAll(() => seed());
test.afterEach(closePersonPages);

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
    // The code step is still in the sign-in page's language: the saved choice applies once signed in.
    await submitCode(page, FI_ADMIN.secret, 'en');

    await expect(page).toHaveURL('/');
    await expect(page.getByRole('heading', { level: 1, name: 'Yleisnäkymä' })).toBeVisible();
    await expect(page.getByText('Viimeisimmät tapahtumat')).toBeVisible();
    await expect(page.locator('html')).toHaveAttribute('lang', 'fi');
    await expect(page.getByRole('heading', { level: 1, name: 'Dashboard' })).toHaveCount(0);
});

test('an admin without two-factor can switch My profile to Finnish, and nothing else opens', async ({ page }) => {
    await page.goto('/signin');
    await submitSignIn(page, NOFACTOR_ADMIN.password, { email: NOFACTOR_ADMIN.email });
    await expect(page).toHaveURL('/profile#two-factor');

    // Saving the language is one of the few things the server still allows (PATCH /me).
    await page.getByLabel('Language').selectOption('fi');
    await expect(page.getByText('Ota kaksivaiheinen tunnistautuminen käyttöön jatkaaksesi.')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Ota käyttöön' })).toBeVisible();

    // The server refuses the dashboard's data (403 two_factor_required), so the console comes back here.
    await page.goto('/');
    await expect(page).toHaveURL('/profile#two-factor');
    await expect(page.getByText('Ota kaksivaiheinen tunnistautuminen käyttöön jatkaaksesi.')).toBeVisible();
    await expect(page.getByRole('heading', { level: 1, name: 'Yleisnäkymä' })).toHaveCount(0);
});

test('creating a mailbox shows the Finnish shown-once password panel', async ({ page }) => {
    await signInFinnish(page);
    await page.goto('/domains/example.org/mailboxes');
    await page.getByRole('button', { name: /Lisää postilaatikko/ }).click();

    const drawer = page.getByRole('dialog', { name: 'Uusi postilaatikko' });
    await drawer.getByLabel('Osoite', { exact: true }).fill('e2e-fi');
    await expect(drawer.getByRole('status')).toBeVisible();
    await drawer.getByRole('button', { name: 'Luo', exact: true }).click();
    const password = await drawer.getByLabel('Salasana', { exact: true }).inputValue();
    expect(password).toHaveLength(16);
    await drawer.getByRole('button', { name: 'Luo postilaatikko' }).click();

    const created = page.getByRole('dialog', { name: 'Postilaatikko luotu' });
    await expect(created.getByText('Salasana – näytetään vain kerran')).toBeVisible();
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

test('an invitation sent in Finnish is accepted on the Finnish invitation page, and the console stays Finnish', async ({ page, browser }) => {
    await signInFinnish(page);
    await page.goto('/domains/example.org/access');
    await page.getByRole('button', { name: 'Jaa käyttöoikeus' }).first().click();

    const dialog = page.getByRole('dialog', { name: 'Jaa käyttöoikeus verkkotunnukseen example.org' });
    await dialog.getByLabel('Sähköposti', { exact: true }).fill(INVITEE);
    await dialog.getByRole('radio', { name: /^Katselija/ }).check();
    // The mail's language starts as the console's.
    await expect(dialog.getByLabel('Sähköpostin kieli')).toHaveValue('fi');
    await dialog.getByRole('button', { name: 'Lähetä kutsu' }).click();
    await confirmStepUp(page, FI_ADMIN.password, 'fi');
    await expect(dialog).toHaveCount(0);
    await expect(page.getByText(`Kutsu lähetettiin osoitteeseen ${INVITEE}.`)).toBeVisible();

    // The mail itself is in Finnish (`locale: fi`), and carries the link.
    await readMailLink(/Linkki on voimassa 72 tuntia/);
    const link = await readMailLink(INVITE_LINK);

    const invitee = await newPersonPage(browser);
    await useLocale(invitee, 'fi');
    await acceptAsNewPerson(invitee, link, 'Kaisa Katselija', 'fi');

    // The new user's language is the invitation's: the console opens on the domain in Finnish.
    await expect(invitee).toHaveURL('/domains/example.org/mailboxes');
    await expect(invitee.getByText('Tervetuloa EmailSquireen.')).toBeVisible();
    await expect(invitee.getByText('Voit katsella tätä verkkotunnusta')).toBeVisible();
    await expect(invitee.locator('html')).toHaveAttribute('lang', 'fi');
});

test('the invitation page fits the width in Finnish and breaks no CSP rule', async ({ page }) => {
    const consoleErrors = await watchCsp(page);
    await useLocale(page, 'fi');

    await checkLayout(page, `/invite#${PENDING_INVITATION_TOKEN}`, 'Verkkotunnus');
    await expect(page.getByRole('heading', { name: 'Sinut on kutsuttu' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Hyväksy ja luo tili' })).toBeVisible();

    expectNoCspConsoleErrors(consoleErrors);
});
