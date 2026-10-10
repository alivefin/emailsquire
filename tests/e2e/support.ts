import { execFileSync } from 'node:child_process';
import { expect, type Locator, type Page } from '@playwright/test';

export const ADMIN = { email: 'admin@example.org', password: 'Summersun-e2e-2026!' };

/**
 * Resets both e2e schemas and the admin. Every spec calls it in beforeAll: the data-changing specs
 * need a clean fixture, and the reset also empties the login limiter (five sign-ins a minute).
 */
export function seed(): void {
    const out = execFileSync('php', ['tests/e2e/seed.php'], { encoding: 'utf8' });
    if (!out.includes('seeded')) throw new Error(`seed.php did not finish: ${out}`);
}

/** Fills and submits the sign-in form on the current page. */
export async function submitSignIn(page: Page, password = ADMIN.password): Promise<void> {
    await page.getByLabel('Email').fill(ADMIN.email);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: 'Continue' }).click();
}

/** Signs in from /signin and waits for the dashboard. */
export async function signIn(page: Page): Promise<void> {
    await page.goto('/signin');
    await submitSignIn(page);
    await expect(page).toHaveURL('/');
    await expect(page.getByRole('heading', { level: 1, name: 'Dashboard' })).toBeVisible();
}

/**
 * The table row (desktop) or card (phone) of `address`: the innermost element holding the address text,
 * its "Actions for …" menu button and, when given, the text `also` (such as a destination shown below
 * the card's header).
 */
export function rowFor(page: Page, address: string, also?: string): Locator {
    let row = page
        .locator('tr, div')
        .filter({ hasText: address })
        .filter({ has: page.getByRole('button', { name: `Actions for ${address}`, exact: true }) });
    if (also !== undefined) row = row.filter({ hasText: also });
    return row.last();
}

/** Opens the row menu of `address` and picks `item`. */
export async function rowAction(page: Page, address: string, item: string): Promise<void> {
    await page.getByRole('button', { name: `Actions for ${address}`, exact: true }).click();
    await page.getByRole('menuitem', { name: item, exact: true }).click();
}
