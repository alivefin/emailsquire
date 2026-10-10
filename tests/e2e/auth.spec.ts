import { expect, test } from '@playwright/test';
import { seed, signIn, submitSignIn } from './support';

test.beforeAll(() => seed());

test('signs in and lands on the dashboard', async ({ page }) => {
    await signIn(page);
    await expect(page.getByText('Your session has expired')).toHaveCount(0);
});

test('a wrong password says so and stays on the sign-in page', async ({ page }) => {
    await page.goto('/signin');
    await submitSignIn(page, 'not-the-password');

    await expect(page.getByRole('alert')).toHaveText(/Email or password is wrong\./);
    await expect(page).toHaveURL('/signin');
});

test('an expired session sends the admin to sign in and back to the same screen', async ({ page, context }) => {
    await signIn(page);
    await page.goto('/domains/example.org/mailboxes?q=jo');
    await expect(page.getByText('john@example.org')).toBeVisible();
    await expect(page.getByText('mary@example.org')).toHaveCount(0);

    await context.clearCookies();

    // A filter change makes a fresh API call, which now gets a 401.
    await page.getByLabel('Status').selectOption('active');
    const expiredAt = '/domains/example.org/mailboxes?q=jo&status=active';

    await expect(page).toHaveURL(/\/signin\?/);
    const url = new URL(page.url());
    expect(url.searchParams.get('next')).toBe(expiredAt);
    expect(url.searchParams.get('expired')).toBe('1');
    await expect(page.getByRole('status').filter({ hasText: 'Your session has expired. Sign in again to continue.' })).toBeVisible();

    await submitSignIn(page);

    await expect(page).toHaveURL(expiredAt);
    await expect(page.getByLabel('Search mailboxes')).toHaveValue('jo');
    await expect(page.getByText('john@example.org')).toBeVisible();
    await expect(page.getByText('mary@example.org')).toHaveCount(0);
});
