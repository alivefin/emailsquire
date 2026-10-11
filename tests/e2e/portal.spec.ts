import { expect, type Page, test } from '@playwright/test';
import {
    checkLayout,
    closePersonPages,
    expectNoCspConsoleErrors,
    INVITEE_PASSWORD,
    newPersonPage,
    openDomainSettings,
    OWNER,
    PORTAL_ROUTES,
    seed,
    signIn,
    signInOwner,
    submitSignIn,
    useLocale,
    watchCsp,
} from './support';

/**
 * The mailbox portal: an owner signs in on the shared sign-in screen with their mail password (checked
 * in e2e by FakeDovecotAuthClient against the stored hash; mail truth covers real Dovecot) and manages
 * their own mailbox within the switches example.org allows (seed.php: forwarding to this server, aliases).
 *
 * Every sign-in counts against `login` (5 a minute per address and IP), which a mailbox sign-in does
 * not clear: OWNER signs in four times per project (the seed resets the limiters), and the password
 * change signs in as mary@example.org, three more, which also keeps OWNER's password the seeded one.
 */

const PASSWORD_OWNER = { email: 'mary@example.org', password: 'summersun' };

test.beforeAll(() => seed());
test.afterEach(closePersonPages);

function portalNav(page: Page) {
    return page.getByRole('navigation', { name: 'Your mailbox' });
}

test('an owner signs in with the mail password, sees their mailbox and help, and cannot reach the console', async ({ page }) => {
    await signInOwner(page);

    await expect(page.getByRole('banner')).toContainText(OWNER.email);
    const storage = page.getByRole('region', { name: 'Storage' });
    await expect(storage.getByText('1.2 GB of 5 GB used')).toBeVisible();
    await expect(storage.getByRole('progressbar')).toBeVisible();
    await expect(page.getByRole('region', { name: 'Forwarding' })).toContainText('Your mail is not forwarded.');
    await expect(page.getByRole('region', { name: 'Aliases' })).toContainText('You have no aliases.');

    // example.org has no manager, so Help names the operator's support contact (.env.e2e).
    await portalNav(page).getByRole('link', { name: 'Help' }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'Help and contact' })).toBeVisible();
    await expect(page.getByTestId('support-contact')).toHaveText('Example Hosting support, help@example.net');

    // The console is not this session's: its screens send it back, and its API answers 401.
    await page.goto('/domains');
    await expect(page).toHaveURL('/my');
    await expect(page.getByRole('heading', { level: 1, name: 'My mailbox' })).toBeVisible();
    expect((await page.request.get('/api/v1/domains')).status()).toBe(401);
    expect((await page.request.get('/api/v1/portal/me')).status()).toBe(200);
});

test('an owner forwards to an address on this server with a copy, adds an alias, and Home shows both', async ({ page }) => {
    await signInOwner(page);

    await portalNav(page).getByRole('link', { name: 'Forwarding' }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'Forwarding' })).toBeVisible();
    const target = page.getByLabel('Forward my mail to');
    await target.fill('john@example.org');
    await target.press('Enter');
    await expect(page.getByText('john@example.org', { exact: true })).toBeVisible();
    await page.getByRole('radio', { name: 'Keep a copy in my mailbox' }).check();
    await page.getByRole('button', { name: 'Save forwarding' }).click();
    await expect(page.getByText('Forwarding saved.')).toBeVisible();

    await portalNav(page).getByRole('link', { name: 'Aliases' }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'Aliases' })).toBeVisible();
    await page.getByLabel('New alias').fill('sales');
    await page.getByRole('button', { name: 'Add alias' }).click();
    await expect(page.getByText('sales@example.org added.')).toBeVisible();
    await expect(page.getByRole('list', { name: 'Your aliases' })).toContainText('sales@example.org');

    await portalNav(page).getByRole('link', { name: 'Home' }).click();
    const forwarding = page.getByRole('region', { name: 'Forwarding' });
    await expect(forwarding).toContainText('Your mail is forwarded to 1 address.');
    await expect(forwarding).toContainText('john@example.org');
    await expect(forwarding).toContainText('A copy stays in your mailbox.');
    const aliases = page.getByRole('region', { name: 'Aliases' });
    await expect(aliases).toContainText('1 extra address delivers to your mailbox.');
    await expect(aliases).toContainText('sales@example.org');
});

test("an admin turns aliases off in Domain settings, and the owner's Aliases page is locked", async ({ page, browser }) => {
    await signInOwner(page);
    await page.goto('/my/aliases');
    await expect(page.getByRole('button', { name: 'Add alias' })).toBeVisible();

    const admin = await newPersonPage(browser);
    await signIn(admin);
    await admin.goto('/domains/example.org/mailboxes');
    await expect(admin.getByText('john@example.org').first()).toBeVisible();
    const settings = await openDomainSettings(admin);
    const aliasesSwitch = settings.getByRole('switch', { name: 'Add and remove aliases' });
    await expect(aliasesSwitch).toHaveAttribute('aria-checked', 'true');
    await aliasesSwitch.click();
    await expect(aliasesSwitch).toHaveAttribute('aria-checked', 'false');
    await settings.getByRole('button', { name: 'Save' }).click();
    await expect(settings).toHaveCount(0);
    await expect(admin.getByText('Settings saved.')).toBeVisible();

    // The owner's next look at the page shows the locked state: no form, the reason, and who to ask.
    await page.reload();
    await expect(page.getByRole('heading', { level: 1, name: 'Aliases' })).toBeVisible();
    await expect(page.getByText('Your administrator has turned this off.')).toBeVisible();
    await expect(page.getByText('Ask your administrator to add one.')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Add alias' })).toHaveCount(0);
    await expect(page.getByLabel('New alias')).toHaveCount(0);

    await portalNav(page).getByRole('link', { name: 'Home' }).click();
    const aliases = page.getByRole('region', { name: 'Aliases' });
    await expect(aliases).toContainText('Your administrator has turned this off.');
    await expect(aliases.getByRole('link', { name: 'View aliases' })).toBeVisible();
});

test('an owner changes the password, and only the new one signs in afterwards', async ({ page }) => {
    await signInOwner(page, PASSWORD_OWNER);

    await portalNav(page).getByRole('link', { name: 'Password' }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'Change password' })).toBeVisible();
    await page.getByLabel('Current password', { exact: true }).fill(PASSWORD_OWNER.password);
    await page.getByLabel('New password', { exact: true }).fill(INVITEE_PASSWORD);
    await page.getByLabel('Confirm new password', { exact: true }).fill(INVITEE_PASSWORD);
    await page.getByRole('button', { name: 'Change password' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Password changed.' })).toBeVisible();

    await page.getByRole('button', { name: 'Sign out' }).click();
    await expect(page).toHaveURL('/signin');

    await submitSignIn(page, PASSWORD_OWNER.password, { email: PASSWORD_OWNER.email });
    await expect(page.getByRole('alert')).toHaveText(/Email or password is wrong\./);
    await expect(page).toHaveURL('/signin');

    await signInOwner(page, { email: PASSWORD_OWNER.email, password: INVITEE_PASSWORD });
    await expect(page.getByRole('banner')).toContainText(PASSWORD_OWNER.email);
});

test('the portal in Finnish fits the width and breaks no CSP rule', async ({ page }) => {
    const consoleErrors = await watchCsp(page);
    await useLocale(page, 'fi');
    await signInOwner(page, OWNER, 'fi');

    await expect(page.locator('html')).toHaveAttribute('lang', 'fi');
    await expect(page.getByRole('region', { name: 'Tallennustila' }).getByText('1,2 Gt / 5 Gt käytetty')).toBeVisible();
    await expect(page.getByRole('navigation', { name: 'Postilaatikkosi' }).getByRole('link', { name: 'Edelleenlähetys' })).toBeVisible();

    for (const route of PORTAL_ROUTES) {
        await test.step(route, () => checkLayout(page, route, 'Verkkotunnus'));
    }
    await expect(page.getByRole('heading', { level: 1, name: 'Ohjeet ja yhteystiedot' })).toBeVisible();

    expectNoCspConsoleErrors(consoleErrors);
});
