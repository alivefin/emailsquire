import { expect, test } from '@playwright/test';
import { ADMIN, freshCode, NOFACTOR_ADMIN, rowAction, seed, signIn, submitSignIn } from './support';

/**
 * Two-factor sign-in, the required setup for an admin without it, and step-up before a dangerous
 * action. The Finnish code step runs in finnish.spec.
 */

const DOMAIN = 'e2e-stepup.example';

test.beforeAll(() => seed());

test('the admin with two-factor signs in with a code from the authenticator app', async ({ page }) => {
    await page.goto('/signin');
    await submitSignIn(page);

    await expect(page.getByRole('heading', { name: 'Enter your code' })).toBeVisible();
    await expect(page.getByText(ADMIN.email)).toBeVisible();
    // Still on the sign-in page: the password alone opens nothing.
    await expect(page).toHaveURL('/signin');

    const field = page.getByLabel('Two-factor code', { exact: true });
    await expect(field).toBeFocused();
    await field.fill(await freshCode(ADMIN.secret));
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();

    await expect(page).toHaveURL('/');
    await expect(page.getByRole('heading', { level: 1, name: 'Dashboard' })).toBeVisible();
});

test('an admin without two-factor sets it up on My profile before reaching the console', async ({ page }) => {
    await page.goto('/signin');
    await submitSignIn(page, NOFACTOR_ADMIN.password, { email: NOFACTOR_ADMIN.email });

    // Required for admins: the console opens only on My profile's two-factor section.
    await expect(page).toHaveURL('/profile#two-factor');
    await expect(page.getByText('Set up two-factor sign-in to continue.')).toBeVisible();

    await page.getByRole('button', { name: 'Turn on' }).click();

    // Setting it up needs a step-up; without two-factor yet, the dialog asks for the password only.
    const stepUp = page.getByRole('dialog', { name: "Confirm it's you" });
    await expect(stepUp.getByRole('button', { name: 'Use a code instead' })).toHaveCount(0);
    await stepUp.getByLabel('Password', { exact: true }).fill(NOFACTOR_ADMIN.password);
    await stepUp.getByRole('button', { name: 'Confirm', exact: true }).click();
    await expect(stepUp).toHaveCount(0);

    const setup = page.getByRole('group', { name: 'Set up your authenticator app' });
    await expect(setup.getByRole('img', { name: 'QR code for your authenticator app' })).toBeVisible();
    const secret = (await setup.locator('code').textContent())?.trim() ?? '';
    expect(secret).toMatch(/^[A-Z2-7]{16,}$/);

    await setup.getByLabel('Code from the app').fill(await freshCode(secret));
    await setup.getByRole('button', { name: 'Confirm and turn on' }).click();

    const codes = page.getByRole('group', { name: 'Save your recovery codes' });
    await expect(codes.getByRole('list', { name: 'Recovery codes' }).getByRole('listitem')).toHaveCount(8);
    await codes.getByRole('button', { name: 'Done' }).click();
    await expect(codes).toHaveCount(0);
    await expect(page.getByText('8 recovery codes left')).toBeVisible();
    await expect(page.getByText('Set up two-factor sign-in to continue.')).toHaveCount(0);

    await page.goto('/');
    await expect(page.getByRole('heading', { level: 1, name: 'Dashboard' })).toBeVisible();
});

test('deleting a domain asks for step-up, and the password confirms it', async ({ page }) => {
    await signIn(page);
    await page.goto('/domains');
    await page.getByRole('button', { name: '+ Add domain' }).click();
    await page.getByLabel('Domain name').fill(DOMAIN);
    await page.getByRole('button', { name: 'Add domain', exact: true }).click();
    await expect(page).toHaveURL(`/domains/${DOMAIN}/mailboxes`);

    await page.goto('/domains');
    await rowAction(page, DOMAIN, 'Delete…');
    const dialog = page.getByRole('dialog', { name: `Delete ${DOMAIN}?` });
    await dialog.getByLabel(`Type ${DOMAIN} to confirm`).fill(DOMAIN);
    await dialog.getByRole('button', { name: 'Delete domain' }).click();

    // The server answers 423 step_up_required; an admin with two-factor is offered the code first.
    const stepUp = page.getByRole('dialog', { name: "Confirm it's you" });
    await expect(stepUp.getByLabel('Two-factor code')).toBeVisible();

    // Cancelling leaves the request failed and the domain in place.
    await stepUp.getByRole('button', { name: 'Cancel' }).click();
    await expect(stepUp).toHaveCount(0);
    await expect(dialog.getByText("Confirm it's you to continue.")).toBeVisible();
    await page.reload();
    await expect(page.getByRole('link', { name: DOMAIN })).toBeVisible();

    await rowAction(page, DOMAIN, 'Delete…');
    await dialog.getByLabel(`Type ${DOMAIN} to confirm`).fill(DOMAIN);
    await dialog.getByRole('button', { name: 'Delete domain' }).click();
    await stepUp.getByRole('button', { name: 'Use password instead' }).click();

    // A wrong password keeps the dialog open and the domain in place.
    await stepUp.getByLabel('Password', { exact: true }).fill('not-the-password');
    await stepUp.getByRole('button', { name: 'Confirm', exact: true }).click();
    await expect(stepUp.getByText('The provided password is incorrect.')).toBeVisible();

    await stepUp.getByLabel('Password', { exact: true }).fill(ADMIN.password);
    await stepUp.getByRole('button', { name: 'Confirm', exact: true }).click();

    // The delete is retried once confirmed.
    await expect(stepUp).toHaveCount(0);
    await expect(dialog).toHaveCount(0);
    await expect(page.getByRole('link', { name: DOMAIN })).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'example.org', exact: true })).toBeVisible();
});
