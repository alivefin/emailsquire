import { expect, test } from '@playwright/test';
import {
    checkLayout,
    createMailbox,
    expectNoCspConsoleErrors,
    MAIN_ROUTES,
    PENDING_INVITATION_TOKEN,
    PORTAL_ROUTES,
    seed,
    setCatchAll,
    signIn,
    signInOwner,
    watchCsp,
} from './support';

test.beforeAll(() => seed());

test('every main screen fits the width and breaks no CSP rule', async ({ page }) => {
    const consoleErrors = await watchCsp(page);

    await signIn(page);

    for (const route of MAIN_ROUTES) {
        await test.step(route, () => checkLayout(page, route, 'Domain'));
    }

    expectNoCspConsoleErrors(consoleErrors);
});

test('the activity log and the domain Activity tab fit the width with long entries in them', async ({ page }) => {
    const consoleErrors = await watchCsp(page);

    await signIn(page);
    // A long address and recipient: the sentence must wrap, not push the table or the cards wider.
    const local = 'a-rather-long-mailbox-name-for-the-activity-layout';
    await createMailbox(page, 'example.org', local, { welcomeTo: 'someone.with-a-long-address-too@example.net' });

    for (const route of ['/activity', '/activity?group=mailboxes', '/domains/example.org/activity']) {
        await test.step(route, async () => {
            await checkLayout(page, route, 'Domain');
            await expect(page.getByRole('main').getByText(`${local}@example.org`).filter({ visible: true }).first()).toBeVisible();
        });
    }

    expectNoCspConsoleErrors(consoleErrors);
});

test('the DNS tab with records to publish, and the forwarding tab with a catch-all, fit the width', async ({ page }) => {
    const consoleErrors = await watchCsp(page);

    await signIn(page);

    // example.net misses DMARC and its DKIM record (tests/e2e/fixtures/dns.json): the longest values to
    // publish (a 2048-bit key's record and its zone line) must wrap inside their rows.
    await checkLayout(page, '/domains/example.net/dns', 'Domain');
    await expect(page.getByRole('region', { name: 'DMARC', exact: true })).toContainText('Publish this:');
    await expect(page.getByRole('region', { name: 'DKIM keys' }).getByRole('listitem')).toHaveCount(1);

    await setCatchAll(page, 'example.org', ['john@example.org', 'someone.with-a-rather-long-address@example.net']);
    await checkLayout(page, '/domains/example.org/forwarding', 'Domain');
    await expect(page.getByRole('button', { name: 'Actions for Catch-all @example.org', exact: true })).toBeVisible();
    await expect(page.getByText('Catch-all · mail to any other address').filter({ visible: true }).first()).toBeVisible();

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

test('every mailbox portal screen fits the width and breaks no CSP rule', async ({ page }) => {
    const consoleErrors = await watchCsp(page);

    await signInOwner(page);

    for (const route of PORTAL_ROUTES) {
        await test.step(route, () => checkLayout(page, route, 'Domain'));
    }

    expectNoCspConsoleErrors(consoleErrors);
});
