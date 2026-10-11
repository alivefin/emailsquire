import { expect, type Page, test } from '@playwright/test';
import { addVirtualAuthenticator, confirmStepUp, NOFACTOR_ADMIN, rowAction, seed, submitSignIn } from './support';

const PASSKEY = 'My test device';
const DOMAIN = 'e2e-passkey.example';

// CDP's virtual authenticator is available only in Chromium. Both desktop and phone use it.
test.beforeEach(({ browserName }) => {
    test.skip(browserName !== 'chromium', 'The virtual authenticator needs Chromium CDP.');
    seed();
});

/** First second factor: no TOTP and no existing recovery codes on this seeded admin. */
async function addFirstPasskey(page: Page): Promise<void> {
    await page.goto('/signin');
    await submitSignIn(page, NOFACTOR_ADMIN.password, { email: NOFACTOR_ADMIN.email });
    await expect(page).toHaveURL('/profile#two-factor');
    await expect(page.getByText('Set up two-factor sign-in to continue.')).toBeVisible();

    await page.getByRole('button', { name: 'Add a passkey', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'Add a passkey' });
    await dialog.getByLabel('Passkey name', { exact: true }).fill(PASSKEY);
    await dialog.getByRole('button', { name: 'Add a passkey', exact: true }).click();
    await confirmStepUp(page, NOFACTOR_ADMIN.password);
    await expect(dialog).toHaveCount(0);

    const codes = page.getByRole('group', { name: 'Save your recovery codes' });
    await expect(codes.getByRole('list', { name: 'Recovery codes' }).getByRole('listitem')).toHaveCount(8);
    await codes.getByRole('button', { name: 'Done', exact: true }).click();
    await expect(codes).toHaveCount(0);
    await expect(page.getByRole('list', { name: 'Passkeys', exact: true }).getByRole('listitem')).toHaveCount(1);
    await expect(page.getByText(PASSKEY, { exact: true })).toBeVisible();
    await expect(page.getByText('Set up two-factor sign-in to continue.')).toHaveCount(0);
}

test('adds a passkey, signs in with it alone and confirms a domain deletion with it', async ({ page }) => {
    const removeAuthenticator = await addVirtualAuthenticator(page);
    try {
        // One password sign-in; no TOTP codes are spent. Registration uses 3/6 step-up attempts.
        await addFirstPasskey(page);
        await page.getByRole('button', { name: 'Account menu' }).click();
        await page.getByRole('menuitem', { name: 'Sign out', exact: true }).click();
        await expect(page).toHaveURL('/signin');

        await expect(page.getByLabel('Email', { exact: true })).toHaveValue('');
        await expect(page.getByLabel('Password', { exact: true })).toHaveValue('');
        await page.getByRole('button', { name: 'Sign in with a passkey', exact: true }).click();
        await expect(page).toHaveURL('/');
        await expect(page.getByRole('heading', { level: 1, name: 'Dashboard' })).toBeVisible();

        await page.goto('/domains');
        await page.getByRole('button', { name: '+ Add domain', exact: true }).click();
        await page.getByLabel('Domain name').fill(DOMAIN);
        await page.getByRole('button', { name: 'Add domain', exact: true }).click();
        await expect(page).toHaveURL(`/domains/${DOMAIN}/mailboxes`);
        await page.goto('/domains');
        await rowAction(page, DOMAIN, 'Delete…');
        const dialog = page.getByRole('dialog', { name: `Delete ${DOMAIN}?` });
        await dialog.getByLabel(`Type ${DOMAIN} to confirm`).fill(DOMAIN);
        await dialog.getByRole('button', { name: 'Delete domain', exact: true }).click();

        // Signing in cleared registration's step-up window. This assertion uses another 2/6 attempts.
        const stepUp = page.getByRole('dialog', { name: "Confirm it's you" });
        await stepUp.getByRole('button', { name: 'Use a passkey', exact: true }).click();
        await expect(stepUp).toHaveCount(0);
        await expect(dialog).toHaveCount(0);
        await expect(page.getByRole('link', { name: DOMAIN, exact: true })).toHaveCount(0);
        await expect(page.getByRole('link', { name: 'example.org', exact: true })).toBeVisible();
    } finally {
        await removeAuthenticator();
    }
});

test('refuses to remove the only passkey when the admin has no TOTP and must have two-factor', async ({ page }) => {
    const removeAuthenticator = await addVirtualAuthenticator(page);
    try {
        await addFirstPasskey(page);
        await page.getByRole('button', { name: `Remove ${PASSKEY}`, exact: true }).click();
        const dialog = page.getByRole('dialog', { name: 'Remove passkey?' });
        const refused = page.waitForResponse((response) =>
            /\/api\/v1\/me\/passkeys\/\d+$/.test(response.url()) && response.request().method() === 'DELETE',
        );
        // Registration's password step-up is still fresh; the server must reach the last-factor check.
        await dialog.getByRole('button', { name: 'Remove passkey', exact: true }).click();
        const response = await refused;
        expect(response.status()).toBe(409);
        expect((await response.json()).code).toBe('last_second_factor');
        await expect(dialog.getByRole('alert')).toHaveText('You cannot remove your last second factor. Add another passkey or set up an authenticator app first.');
        await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
        await page.reload();
        await expect(page.getByRole('list', { name: 'Passkeys', exact: true }).getByRole('listitem')).toHaveCount(1);
        await expect(page.getByText(PASSKEY, { exact: true })).toBeVisible();
    } finally {
        await removeAuthenticator();
    }
});
