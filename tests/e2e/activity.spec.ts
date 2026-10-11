import { readFileSync } from 'node:fs';
import { expect, type Page, test } from '@playwright/test';
import {
    acceptAsNewPerson,
    closePersonPages,
    createMailbox,
    newPersonPage,
    readMail,
    seed,
    shareDomain,
    signIn,
    signInFinnish,
} from './support';

/**
 * The activity log and the welcome email: the admin creates a mailbox and sends its setup instructions
 * (never the password), the log shows both, and a manager of example.org reads that domain's history
 * but not the admin's account changes or anything on example.net (seed.php's second domain).
 */

const ADDRESS = 'e2e-welcome@example.org';
const RECIPIENT = 'someone@example.net';
const MANAGER = 'manager@example.org';
/** A mailbox on example.net: its row is the admin's, and no manager of example.org may read it. */
const ELSEWHERE = 'e2e-elsewhere@example.net';

/** The `account` group (AuditActions::GROUPS, exported for the SPA): admin-only rows. */
const ACCOUNT_ACTIONS: string[] = (JSON.parse(readFileSync('resources/js/api/auditActions.json', 'utf8')) as Record<string, string[]>).account ?? [];

test.beforeAll(() => seed());
test.afterEach(closePersonPages);

/** The visible element with `text` (the table on a wide screen, the cards on a phone). */
function shown(page: Page, text: string) {
    return page.getByRole('main').getByText(text).filter({ visible: true }).first();
}

test('the admin sends a new mailbox its setup instructions, and the activity log shows both', async ({ page }) => {
    await signIn(page);
    const password = await createMailbox(page, 'example.org', 'e2e-welcome', { welcomeTo: RECIPIENT });

    // The mail has the address, the servers and the webmail link, and no password in any form.
    const mail = await readMail(RECIPIENT, `Your mailbox ${ADDRESS} is ready`);
    expect(mail).toContain(`Username: ${ADDRESS}`);
    expect(mail).toContain('Incoming mail (IMAP): mail.example.org:993, SSL/TLS');
    expect(mail).toContain('Outgoing mail (SMTP): mail.example.org:587, STARTTLS');
    expect(mail).toContain('https://webmail.example.org/');
    expect(mail).toContain('Your administrator will give you your password.');
    expect(mail).not.toContain(password);
    expect(mail).not.toContain('{BLF-CRYPT}');
    expect(mail).not.toContain('$2y$');

    // The domain's Activity tab lists them and leads to the full log.
    await page.goto('/domains/example.org/activity');
    await expect(shown(page, `created mailbox ${ADDRESS}`)).toBeVisible();
    await page.getByRole('link', { name: 'Open in the activity log →' }).click();
    await expect(page).toHaveURL('/activity?domain=example.org');

    // The action filter lives in the URL and survives a reload.
    await page.goto('/activity');
    const group = page.getByRole('main').getByRole('combobox', { name: 'Action', exact: true });
    await group.selectOption('mailboxes');
    await expect(page).toHaveURL('/activity?group=mailboxes');
    await page.reload();
    await expect(group).toHaveValue('mailboxes');
    await expect(shown(page, `created mailbox ${ADDRESS}`)).toBeVisible();
    await expect(shown(page, `sent the setup instructions for ${ADDRESS} to ${RECIPIENT}`)).toBeVisible();
    await expect(page.getByText('2 entries', { exact: true })).toBeVisible();

    // Another group has none of it; "Clear filters" brings everything back.
    await group.selectOption('access');
    await expect(page).toHaveURL('/activity?group=access');
    await expect(page.getByText('No activity matches these filters')).toBeVisible();
    await page.getByRole('button', { name: 'Clear filters' }).first().click();
    await expect(page).toHaveURL('/activity');
    await expect(shown(page, `created mailbox ${ADDRESS}`)).toBeVisible();
});

test('a manager of example.org reads its history, but no account rows and nothing on example.net', async ({ page, browser }) => {
    await signIn(page);

    // Rows the manager must not read: a mailbox on example.net, and the admin's own account change.
    await createMailbox(page, 'example.net', 'e2e-elsewhere');
    await page.goto('/profile');
    await page.getByRole('button', { name: 'Sign out everywhere' }).click();
    await page.getByRole('dialog', { name: 'Sign out everywhere?' }).getByRole('button', { name: 'Sign out everywhere' }).click();
    await expect(page.getByText('Signed out everywhere else. This browser stays signed in.')).toBeVisible();

    // The admin reads both.
    await page.goto('/activity');
    await expect(shown(page, `created mailbox ${ELSEWHERE}`)).toBeVisible();
    await expect(shown(page, 'signed admin@example.org out everywhere else')).toBeVisible();

    const link = await shareDomain(page, { domain: 'example.org', email: MANAGER, role: 'manager' });
    const manager = await newPersonPage(browser);
    await acceptAsNewPerson(manager, link, 'Maria Manager');
    await expect(manager).toHaveURL('/domains/example.org/mailboxes');

    await manager.goto('/activity');
    const main = manager.getByRole('main');
    await expect(shown(manager, 'invited manager@example.org to example.org as manager')).toBeVisible();
    await expect(shown(manager, 'accepted the invitation to example.org as manager')).toBeVisible();
    await expect(main).not.toContainText(ELSEWHERE);
    await expect(main).not.toContainText('out everywhere else');

    // Their filters offer example.org only, and no account group.
    await expect(main.getByRole('combobox', { name: 'Domain', exact: true }).locator('option')).toHaveText(['All domains', 'example.org']);
    await expect(main.getByRole('combobox', { name: 'Action', exact: true }).locator('option', { hasText: 'Users' })).toHaveCount(0);

    // Asking for them in the URL finds nothing: the account group, example.net, the admin's rows.
    await manager.goto('/activity?group=account');
    await expect(main.getByText('No activity matches these filters')).toBeVisible();
    await manager.goto('/activity?domain=example.net');
    await expect(main.getByText('No activity matches these filters')).toBeVisible();
    await manager.goto('/activity?actor=1');
    await expect(shown(manager, 'invited manager@example.org to example.org as manager')).toBeVisible();
    await expect(main).not.toContainText(ELSEWHERE);
    await expect(main).not.toContainText('out everywhere else');

    // The API under the screen agrees: every row is example.org's, none from the account group.
    const response = await manager.request.get('/api/v1/audit-log?per_page=200', { headers: { Accept: 'application/json' } });
    expect(response.status()).toBe(200);
    const rows = ((await response.json()) as { data: { action: string; domain_name: string | null }[] }).data;
    expect(rows.length).toBeGreaterThan(0);
    expect(rows.filter((r) => r.domain_name !== 'example.org' || ACCOUNT_ACTIONS.includes(r.action))).toEqual([]);
});

test('in Finnish: setup instructions from the edit drawer, in the mail and the activity log', async ({ page }) => {
    await signInFinnish(page);
    await page.goto('/domains/example.org/mailboxes');
    await page.getByRole('button', { name: 'Toiminnot: john@example.org', exact: true }).click();
    await page.getByRole('menuitem', { name: 'Muokkaa', exact: true }).click();
    await page.getByRole('button', { name: 'Lähetä käyttöönotto-ohjeet', exact: true }).click();

    const dialog = page.getByRole('dialog', { name: 'Lähetä käyttöönotto-ohjeet: john@example.org' });
    await dialog.getByLabel('Vastaanottaja', { exact: true }).fill(RECIPIENT);
    // The mail's language starts as the console's.
    await expect(dialog.getByLabel('Sähköpostin kieli')).toHaveValue('fi');
    await dialog.getByRole('button', { name: 'Lähetä', exact: true }).click();
    await expect(dialog).toHaveCount(0);
    await expect(page.getByText(`Käyttöönotto-ohjeet lähetetty osoitteeseen ${RECIPIENT}.`)).toBeVisible();

    const mail = await readMail(RECIPIENT, 'Postilaatikkosi john@example.org on valmis');
    expect(mail).toContain('Saapuva posti (IMAP): mail.example.org:993, SSL/TLS');
    expect(mail).toContain('Lähtevä posti (SMTP): mail.example.org:587, STARTTLS');
    expect(mail).toContain('Saat salasanan ylläpitäjältäsi.');
    expect(mail).not.toContain('{BLF-CRYPT}');

    await page.goto('/activity?group=mailboxes');
    await expect(page.getByRole('heading', { level: 1, name: 'Tapahtumaloki' })).toBeVisible();
    await expect(page.getByRole('main').getByRole('combobox', { name: 'Tapahtumatyyppi', exact: true })).toHaveValue('mailboxes');
    await expect(shown(page, `lähetit postilaatikon john@example.org käyttöönotto-ohjeet osoitteeseen ${RECIPIENT}`)).toBeVisible();
});
