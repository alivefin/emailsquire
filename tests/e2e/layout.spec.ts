import { expect, test } from '@playwright/test';
import { checkLayout, expectNoCspConsoleErrors, MAIN_ROUTES, PENDING_INVITATION_TOKEN, seed, signIn, watchCsp } from './support';

test.beforeAll(() => seed());

test('every main screen fits the width and breaks no CSP rule', async ({ page }) => {
    const consoleErrors = await watchCsp(page);

    await signIn(page);

    for (const route of MAIN_ROUTES) {
        await test.step(route, () => checkLayout(page, route, 'Domain'));
    }

    expectNoCspConsoleErrors(consoleErrors);
});

test('the invitation page fits the width and breaks no CSP rule', async ({ page }) => {
    const consoleErrors = await watchCsp(page);

    // Signed out, as a new invitee opens it: the details, the note and the new-person form.
    await checkLayout(page, `/invite#${PENDING_INVITATION_TOKEN}`, 'Domain');
    await expect(page.getByRole('heading', { name: "You're invited" })).toBeVisible();
    await expect(page.getByLabel('Email', { exact: true })).toHaveValue('pending@example.org');
    await expect(page.getByRole('button', { name: 'Accept and create account' })).toBeVisible();

    expectNoCspConsoleErrors(consoleErrors);
});
