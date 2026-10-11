import { execFileSync } from 'node:child_process';
import { expect, type Page, test } from '@playwright/test';
import { acceptAsNewPerson, closePersonPages, confirmStepUp, newPersonPage, seed, shareDomain, signIn, signInFinnish } from './support';

/**
 * The DNS tab. DNS is the fixture tests/e2e/fixtures/dns.json (FakeDnsResolver, bound only in APP_ENV=e2e):
 * example.org is green on every check; example.net has its MX, SPF and PTR, but no DMARC and no record for
 * its active DKIM key (seed.php). No emailsquire-dkim.timer runs here: after Generate the spec runs
 * tests/e2e/dkim-helper.php, the real DKIM helper class against a throwaway key directory, so the card
 * shows a real helper result.
 */

test.beforeAll(() => seed());
test.afterEach(closePersonPages);

/** One check's row (a card on a phone), named by its heading. */
function check(page: Page, name: string) {
    return page.getByRole('region', { name, exact: true });
}

/** Runs the DKIM helper's stand-in over the pending tasks and returns its summary line. */
function runDkimHelper(): string {
    return execFileSync('php', ['tests/e2e/dkim-helper.php'], { encoding: 'utf8' });
}

test('example.org is green on every check, and Check now checks again', async ({ page }) => {
    await signIn(page);
    await page.goto('/domains/example.org');
    await page.getByRole('link', { name: 'DNS', exact: true }).click();
    await expect(page).toHaveURL('/domains/example.org/dns');
    await expect(page.getByRole('heading', { name: 'DNS records' })).toBeVisible();

    for (const name of ['MX', 'SPF', 'DKIM', 'DMARC', 'PTR (reverse DNS)']) {
        await expect(check(page, name).getByText('OK', { exact: true }), name).toBeVisible();
    }
    for (const name of ['MTA-STS', 'Autoconfig']) {
        await expect(check(page, name).getByText('Optional', { exact: true }), name).toBeVisible();
    }
    await expect(page.getByText('Problem', { exact: true })).toHaveCount(0);
    await expect(page.getByText('Publish this:')).toHaveCount(0);
    await expect(check(page, 'MX')).toContainText('10 mail.example.org');
    await expect(check(page, 'DMARC')).toContainText('v=DMARC1; p=none; rua=mailto:postmaster@example.org');

    // The DKIM card: the active key, which DNS shows. Only a pending key has actions.
    const card = page.getByRole('region', { name: 'DKIM keys' });
    const key = card.getByRole('listitem').filter({ hasText: 'es20261001' });
    await expect(key).toContainText('Active');
    await expect(key).toContainText('DNS shows its record');
    await expect(card.getByRole('button', { name: /^(Activate|Retire) es/ })).toHaveCount(0);

    // An on-demand check runs inline here (QUEUE_CONNECTION=sync) and lands in the domain's activity.
    await page.getByRole('button', { name: 'Check now' }).click();
    await expect(page.getByText(/^Checked /)).toBeVisible();
    await expect(page.getByRole('button', { name: 'Check now' })).toBeEnabled();
    await page.goto('/domains/example.org/activity');
    await expect(page.getByRole('main').getByText('checked the DNS records of example.org').filter({ visible: true }).first()).toBeVisible();
});

test('example.net shows DMARC and DKIM failing, with the records to copy', async ({ page, context }) => {
    await context.grantPermissions(['clipboard-read', 'clipboard-write']);
    await signIn(page);
    await page.goto('/domains/example.net/dns');

    for (const name of ['MX', 'SPF', 'PTR (reverse DNS)']) {
        await expect(check(page, name).getByText('OK', { exact: true }), name).toBeVisible();
    }

    const dmarc = check(page, 'DMARC');
    await expect(dmarc.getByText('Problem', { exact: true })).toBeVisible();
    await expect(dmarc).toContainText('The domain has no DMARC record.');
    await expect(dmarc).toContainText('Publish this:');
    await expect(dmarc).toContainText('_dmarc.example.net.');
    await expect(dmarc).toContainText('_dmarc.example.net. IN TXT "v=DMARC1; p=none; rua=mailto:postmaster@example.net"');
    await dmarc.getByRole('button', { name: 'Copy the TXT value for _dmarc.example.net.' }).click();
    await expect(dmarc.getByRole('button', { name: 'Copied the TXT value for _dmarc.example.net.' })).toBeVisible();
    expect(await page.evaluate(() => navigator.clipboard.readText())).toBe('v=DMARC1; p=none; rua=mailto:postmaster@example.net');

    const dkim = check(page, 'DKIM');
    await expect(dkim.getByText('Problem', { exact: true })).toBeVisible();
    await expect(dkim).toContainText("DNS doesn't show the active key's record");
    await expect(dkim).toContainText('es20261001._domainkey.example.net.');
    await dkim.getByRole('button', { name: 'Copy the zone file line for es20261001._domainkey.example.net.' }).click();
    const zone = await page.evaluate(() => navigator.clipboard.readText());
    expect(zone).toMatch(/^es20261001\._domainkey\.example\.net\. IN TXT "v=DKIM1; k=rsa; p=MIIB/);

    const key = page.getByRole('region', { name: 'DKIM keys' }).getByRole('listitem').filter({ hasText: 'es20261001' });
    await expect(key).toContainText('Active');
    await expect(key).toContainText('Not in DNS yet');
});

test('an admin creates a DKIM key: pending until the helper has run, then a record to publish and a key to activate', async ({ page }) => {
    await signIn(page);
    await page.goto('/domains/example.net/dns');
    const card = page.getByRole('region', { name: 'DKIM keys' });
    await expect(card.getByRole('listitem')).toHaveCount(1);

    await card.getByRole('button', { name: 'Create a new key' }).click();
    const create = page.getByRole('dialog', { name: 'Create a new DKIM key?' });
    await create.getByRole('button', { name: 'Create key' }).click();
    await confirmStepUp(page);
    await expect(create).toHaveCount(0);
    await expect(page.getByText('The server is creating the key.')).toBeVisible();

    // Queued for the helper: the card says so and offers nothing else meanwhile.
    await expect(card.getByRole('status')).toContainText('Creating a new key on the server…');
    await expect(card.getByRole('button', { name: 'Create a new key' })).toBeDisabled();
    await expect(card.getByRole('button', { name: 'Read keys from the server' })).toBeDisabled();
    await expect(card.getByRole('listitem')).toHaveCount(1);

    // The helper runs; the card, asking every 5 s, reads its result back, and the DNS check looks again.
    expect(runDkimHelper()).toContain('done=1');
    const fresh = card.getByRole('listitem').filter({ hasText: 'Not active yet' });
    await expect(fresh).toBeVisible({ timeout: 15_000 });
    await expect(card.getByRole('status')).toHaveCount(0);
    await expect(fresh).toContainText(/es\d{8}[a-z]?\._domainkey\.example\.net\./);
    await expect(fresh).toContainText('v=DKIM1; k=rsa; p=');
    await expect(fresh).toContainText('Not in DNS yet', { timeout: 10_000 });
    const selector = (await fresh.locator('span.font-mono').first().textContent())?.trim() ?? '';
    expect(selector).toMatch(/^es\d{8}[a-z]?$/);

    // DNS doesn't show it, so activating asks again before it goes ahead.
    await card.getByRole('button', { name: `Activate ${selector}` }).click();
    await page.getByRole('dialog', { name: `Activate ${selector}?` }).getByRole('button', { name: 'Activate', exact: true }).click();
    const anyway = page.getByRole('dialog', { name: `DNS doesn't show ${selector} yet` });
    await expect(anyway).toContainText("receivers can't check mail signed with it");
    await anyway.getByRole('button', { name: 'Activate anyway' }).click();
    await expect(anyway).toHaveCount(0);
    await expect(page.getByText(`rspamd switches to ${selector} within a minute.`)).toBeVisible();
    await expect(card.getByRole('status')).toContainText('Switching rspamd to the new key…');
    await expect(card.getByRole('button', { name: `Activate ${selector}` })).toBeDisabled();

    await page.goto('/domains/example.net/activity');
    const main = page.getByRole('main');
    await expect(main.getByText(`switched example.net to the DKIM key ${selector} before DNS showed it`).filter({ visible: true }).first()).toBeVisible();
    await expect(main.getByText('created a new DKIM key for example.net').filter({ visible: true }).first()).toBeVisible();
});

test('a manager sees the checks and the records, but no DKIM actions', async ({ page, browser }) => {
    await signIn(page);
    const link = await shareDomain(page, { domain: 'example.net', email: 'dns-manager@example.net', role: 'manager' });

    const manager = await newPersonPage(browser);
    await acceptAsNewPerson(manager, link, 'Dana Manager');
    await expect(manager).toHaveURL('/domains/example.net/mailboxes');
    await manager.goto('/domains/example.net/dns');

    await expect(check(manager, 'DMARC').getByText('Problem', { exact: true })).toBeVisible();
    await expect(check(manager, 'DMARC')).toContainText('_dmarc.example.net.');
    await expect(manager.getByRole('button', { name: 'Check now' })).toBeVisible();

    const card = manager.getByRole('region', { name: 'DKIM keys' });
    await expect(card).toContainText('Only server admins can create, activate and retire keys.');
    await expect(card.getByRole('listitem').filter({ hasText: 'es20261001' })).toContainText('Active');
    // Only the records' Copy buttons: nothing that changes a key.
    await expect(card.getByRole('button', { name: /^(Create a new key|Read keys from the server|Activate|Retire)/ })).toHaveCount(0);
    await expect(card.getByRole('button', { name: /^Copy the TXT value for es/ }).first()).toBeVisible();
});

test('the DNS tab in Finnish', async ({ page }) => {
    await signInFinnish(page);
    await page.goto('/domains/example.net/dns');

    await expect(page.getByRole('heading', { name: 'DNS-tietueet' })).toBeVisible();
    await expect(check(page, 'MX').getByText('Kunnossa', { exact: true })).toBeVisible();
    const dmarc = check(page, 'DMARC');
    await expect(dmarc.getByText('Ongelma', { exact: true })).toBeVisible();
    await expect(dmarc).toContainText('Verkkotunnuksella ei ole DMARC-tietuetta.');
    await expect(dmarc).toContainText('Julkaise tämä:');
    await expect(check(page, 'MTA-STS').getByText('Valinnainen', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Tarkista nyt' })).toBeVisible();

    const card = page.getByRole('region', { name: 'DKIM-avaimet' });
    await expect(card.getByRole('listitem').filter({ hasText: 'es20261001' })).toContainText('Käytössä');
    await expect(card.getByRole('button', { name: 'Luo uusi avain' })).toBeVisible();
});
