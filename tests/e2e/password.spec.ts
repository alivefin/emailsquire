import { expect, test } from '@playwright/test';
import { ADMIN, readMailLink, seed, signIn, submitCode, submitSignIn } from './support';

/** Password recovery by email and the password change on My profile. */

const NEW_PASSWORD = 'Wintertide-e2e-2027!';

// Both tests change the admin's password, so each starts from the seeded one.
test.beforeEach(() => seed());

test('a forgotten password is reset from the emailed link, then the new one signs in', async ({ page }) => {
    await page.goto('/signin');
    await page.getByRole('link', { name: 'Forgot password?' }).click();
    await expect(page).toHaveURL('/forgot-password');
    // The sign-in form's Email field is gone once the new page's heading shows.
    await expect(page.getByRole('heading', { name: 'Reset your password' })).toBeVisible();

    await page.getByLabel('Email').fill(ADMIN.email);
    await page.getByRole('button', { name: 'Send link' }).click();
    await expect(page.getByRole('heading', { name: 'Check your email' })).toBeVisible();

    const link = await readMailLink(/http:\/\/localhost:8123\/reset-password\/[0-9a-f]+\?email=[^\s"<>)\]]+/);
    const target = new URL(link);
    await page.goto(target.pathname + target.search);

    await expect(page.getByRole('heading', { name: 'Choose a new password' })).toBeVisible();
    await expect(page.getByLabel('Email')).toHaveValue(ADMIN.email);
    await page.getByLabel('New password', { exact: true }).fill(NEW_PASSWORD);
    await page.getByLabel('Confirm password', { exact: true }).fill(NEW_PASSWORD);
    await page.getByRole('button', { name: 'Set new password' }).click();

    await expect(page).toHaveURL('/signin');
    await expect(page.getByText('Password changed. Sign in with your new password.')).toBeVisible();

    // The old password no longer works; the new one does, with the code step as before.
    await submitSignIn(page, ADMIN.password);
    await expect(page.getByRole('alert')).toHaveText(/Email or password is wrong\./);

    await submitSignIn(page, NEW_PASSWORD);
    await submitCode(page, ADMIN.secret);
    await expect(page).toHaveURL('/');
    await expect(page.getByRole('heading', { level: 1, name: 'Dashboard' })).toBeVisible();
});

test('My profile changes the password, and the new one signs in', async ({ page }) => {
    await signIn(page);
    await page.goto('/profile');

    await page.getByLabel('Current password', { exact: true }).fill(ADMIN.password);
    await page.getByLabel('New password', { exact: true }).fill(NEW_PASSWORD);
    await page.getByLabel('Confirm new password', { exact: true }).fill(NEW_PASSWORD);
    await page.getByRole('button', { name: 'Change password' }).click();
    await expect(page.getByText('Password changed. Your other sessions were signed out.')).toBeVisible();

    // This browser stayed signed in; sign out and back in with the new password.
    await page.getByRole('button', { name: 'Account menu' }).click();
    await page.getByRole('menuitem', { name: 'Sign out' }).click();
    await expect(page).toHaveURL(/\/signin/);

    await submitSignIn(page, NEW_PASSWORD);
    await submitCode(page, ADMIN.secret);
    await expect(page).toHaveURL('/');
    await expect(page.getByRole('heading', { level: 1, name: 'Dashboard' })).toBeVisible();
});
