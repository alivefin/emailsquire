import { execFileSync } from 'node:child_process';
import { createHmac } from 'node:crypto';
import { closeSync, openSync, readSync, statSync } from 'node:fs';
import { expect, type Locator, type Page } from '@playwright/test';

/** An admin `seed.php` creates; `secret` is the TOTP secret of their confirmed two-factor, if any. */
export interface Account {
    email: string;
    password: string;
    secret?: string;
}

/** The first admin, with two-factor on (`seed.php` holds the same secret). */
export const ADMIN = { email: 'admin@example.org', password: 'Summersun-e2e-2026!', secret: 'JBSWY3DPEHPK3PXP' } satisfies Account;

/**
 * The second admin, with `users.locale = 'fi'` saved on the server and two-factor on. Their own secret
 * keeps their codes apart from ADMIN's: each code works once per secret.
 */
export const FI_ADMIN = { email: 'fi-admin@example.org', password: ADMIN.password, secret: 'GEZDGNBVGY3TQOJQ' } satisfies Account;

/** An admin without two-factor: admins must have it, so the console sends them to set it up. */
export const NOFACTOR_ADMIN: Account = { email: 'nofactor-admin@example.org', password: ADMIN.password };

export type Locale = 'en' | 'fi';

/** The sign-in form's labels per language. */
const SIGN_IN_FORM: Record<Locale, { email: string; password: string; submit: string }> = {
    en: { email: 'Email', password: 'Password', submit: 'Continue' },
    fi: { email: 'Sähköposti', password: 'Salasana', submit: 'Jatka' },
};

/** The sign-in code step's labels per language. */
const CODE_STEP: Record<Locale, { code: string; submit: string }> = {
    en: { code: 'Two-factor code', submit: 'Sign in' },
    fi: { code: 'Kaksivaiheisen tunnistautumisen koodi', submit: 'Kirjaudu sisään' },
};

/** The console's main routes: the layout checks run on each of them. */
export const MAIN_ROUTES = [
    '/',
    '/domains',
    '/domains/example.org/mailboxes',
    '/domains/example.org/forwarding',
    '/addresses',
    '/blocked',
    '/profile',
];

const MAIL_LOG = 'storage/logs/e2e-mail.log';

declare global {
    interface Window {
        __csp: string[];
    }
}

/**
 * Resets both e2e schemas and the admins. Every spec calls it in beforeAll: the data-changing specs
 * need a clean fixture, and the reset also empties the cache table, so the rate limiters (five sign-ins
 * and five two-factor challenges a minute) and the server's list of used codes start afresh, and so does
 * the record of used periods here. Mail logged before the reset is ignored by `readMailLink` from now on.
 */
export function seed(): void {
    const out = execFileSync('php', ['tests/e2e/seed.php'], { encoding: 'utf8' });
    if (!out.includes('seeded')) throw new Error(`seed.php did not finish: ${out}`);
    usedPeriods.clear();
    mailLogOffset = mailLogSize();
}

const PERIOD_MS = 30_000;

/** RFC 4648 base32 (no padding), as authenticator apps take the secret. */
function base32Decode(secret: string): Buffer {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const char of secret.replace(/[\s=]/g, '').toUpperCase()) {
        const value = alphabet.indexOf(char);
        if (value < 0) throw new Error(`Not a base32 secret: ${secret}`);
        bits += value.toString(2).padStart(5, '0');
    }
    const bytes: number[] = [];
    for (let i = 0; i + 8 <= bits.length; i += 8) bytes.push(parseInt(bits.slice(i, i + 8), 2));
    return Buffer.from(bytes);
}

/** The TOTP code (RFC 6238: HMAC-SHA1, 30 s step, 6 digits) of `secret` at `at` (ms since the epoch). */
export function totp(secret: string, at = Date.now()): string {
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(at / PERIOD_MS)));
    const hmac = createHmac('sha1', base32Decode(secret)).update(counter).digest();
    const offset = hmac[hmac.length - 1]! & 0x0f;
    const value = (hmac.readUInt32BE(offset) & 0x7fffffff) % 1_000_000;
    return value.toString().padStart(6, '0');
}

/**
 * The periods whose code this worker has sent, per secret. The server accepts a code once (its period is
 * marked used per secret) and only from the previous, current or next period, so a code is never reused.
 * Module state lives as long as the worker, across spec files; `seed()` clears it with the server's list.
 */
const usedPeriods = new Map<string, Set<number>>();

/**
 * A code of `secret` the server has not seen: the current period's, then the next one's, then the
 * previous one's while at least 10 s of the current period remain (it stops working when the period
 * ends). Only when all three are used does it wait for the next period, which brings a new one.
 */
export async function freshCode(secret: string): Promise<string> {
    const used = usedPeriods.get(secret) ?? new Set<number>();
    usedPeriods.set(secret, used);
    for (;;) {
        const now = Date.now();
        const period = Math.floor(now / PERIOD_MS);
        const left = PERIOD_MS - (now % PERIOD_MS);
        const candidates = left >= 10_000 ? [period, period + 1, period - 1] : [period, period + 1];
        const pick = candidates.find((p) => !used.has(p));
        if (pick !== undefined) {
            used.add(pick);
            return totp(secret, pick * PERIOD_MS);
        }
        await new Promise((resolve) => setTimeout(resolve, left + 250));
    }
}

function mailLogSize(): number {
    try {
        return statSync(MAIL_LOG).size;
    } catch {
        return 0;
    }
}

/** Where `readMailLink` starts reading: the log's size at the last `seed()`. */
let mailLogOffset = mailLogSize();

/** The log written since `seed()`. Laravel's log transport writes the bodies already decoded. */
function mailLogSinceSeed(): string {
    const size = mailLogSize();
    // A log truncated meanwhile is read from the start.
    const start = size < mailLogOffset ? 0 : mailLogOffset;
    if (size <= start) return '';
    const fd = openSync(MAIL_LOG, 'r');
    try {
        const buffer = Buffer.alloc(size - start);
        readSync(fd, buffer, 0, buffer.length, start);
        return buffer.toString('utf8');
    } catch {
        return '';
    } finally {
        closeSync(fd);
    }
}

/**
 * The last link matching `pattern` in the mail logged since `seed()` (`MAIL_MAILER=log` writes each
 * message to storage/logs/e2e-mail.log; the queue runs inline). Waits up to 10 s for it to appear.
 */
export async function readMailLink(pattern: RegExp): Promise<string> {
    const all = new RegExp(pattern.source, pattern.flags.includes('g') ? pattern.flags : `${pattern.flags}g`);
    let link: string | undefined;
    await expect
        .poll(
            () => {
                link = Array.from(mailLogSinceSeed().matchAll(all)).at(-1)?.[0];
                return link;
            },
            { message: `a mail with a link matching ${pattern}`, timeout: 10_000 },
        )
        .toBeTruthy();
    return link!;
}

/**
 * Picks the console language before the page loads, as a returning visitor's saved choice would
 * (`localStorage['emailsquire.locale']`). It applies to every page the context opens afterwards.
 */
export async function useLocale(page: Page, locale: Locale): Promise<void> {
    await page.addInitScript((value) => {
        window.localStorage.setItem('emailsquire.locale', value);
    }, locale);
}

/** Fills and submits the sign-in form on the current page, its labels in `locale`. */
export async function submitSignIn(
    page: Page,
    password = ADMIN.password,
    { email = ADMIN.email, locale = 'en' }: { email?: string; locale?: Locale } = {},
): Promise<void> {
    const form = SIGN_IN_FORM[locale];
    await page.getByLabel(form.email).fill(email);
    await page.getByLabel(form.password).fill(password);
    await page.getByRole('button', { name: form.submit }).click();
}

/** Waits for the sign-in code step and submits a fresh code of `secret`, its labels in `locale`. */
export async function submitCode(page: Page, secret: string, locale: Locale = 'en'): Promise<void> {
    const step = CODE_STEP[locale];
    const field = page.getByLabel(step.code, { exact: true });
    await expect(field).toBeVisible();
    await field.fill(await freshCode(secret));
    await page.getByRole('button', { name: step.submit, exact: true }).click();
}

/**
 * Signs in from /signin as `account` (the code step too when they have two-factor) and waits for the
 * English dashboard.
 */
export async function signIn(page: Page, account: Account = ADMIN): Promise<void> {
    await page.goto('/signin');
    await submitSignIn(page, account.password, { email: account.email });
    if (account.secret !== undefined) await submitCode(page, account.secret);
    await expect(page).toHaveURL('/');
    await expect(page.getByRole('heading', { level: 1, name: 'Dashboard' })).toBeVisible();
}

/**
 * Answers the "Confirm it's you" step-up dialog with `password`. An admin with two-factor gets the code
 * form first; the password avoids spending a code.
 */
export async function confirmStepUp(page: Page, password = ADMIN.password): Promise<void> {
    const dialog = page.getByRole('dialog', { name: "Confirm it's you" });
    await expect(dialog).toBeVisible();
    const usePassword = dialog.getByRole('button', { name: 'Use password instead' });
    const passwordField = dialog.getByLabel('Password', { exact: true });
    // The dialog opens on the password (no two-factor) or on the code with a switch to the password:
    // wait until one of them is there before deciding, or isVisible() may look before either renders.
    await expect(passwordField.or(usePassword).first()).toBeVisible();
    if (await usePassword.isVisible()) await usePassword.click();
    await passwordField.fill(password);
    await dialog.getByRole('button', { name: 'Confirm', exact: true }).click();
    await expect(dialog).toHaveCount(0);
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

/**
 * Records Content Security Policy violations from the next page load on: the `securitypolicyviolation`
 * events in `window.__csp` and the console errors that mention CSP. Call it before the first `goto`;
 * `expectNoCspConsoleErrors` reads the console list at the end of the test.
 */
export async function watchCsp(page: Page): Promise<string[]> {
    const consoleErrors: string[] = [];
    page.on('console', (msg) => {
        if (msg.type() === 'error') consoleErrors.push(msg.text());
    });
    await page.addInitScript(() => {
        window.__csp = [];
        document.addEventListener('securitypolicyviolation', (e) => {
            window.__csp.push(`${e.violatedDirective} ${e.blockedURI}`);
        });
    });
    return consoleErrors;
}

export function expectNoCspConsoleErrors(consoleErrors: string[]): void {
    expect(consoleErrors.filter((t) => /content security policy|CSP/i.test(t))).toEqual([]);
}

/**
 * Opens `route` and checks it fits the viewport: no sideways scroll on the page or in <main>, no
 * zoom-out on a phone, no content wider than its box, a usable domain select beside the sidebar, and
 * no CSP violation.
 * `domainSelect` is the accessible name of the header's domain select in the page's language.
 */
export async function checkLayout(page: Page, route: string, domainSelect: string): Promise<void> {
    const viewport = page.viewportSize();
    if (viewport === null) throw new Error('The project sets no viewport.');

    await page.goto(route);
    await expect(page.getByRole('main')).toBeVisible();
    await page.waitForLoadState('networkidle');
    await expect(page.locator('[data-testid="table-skeleton"], [data-testid="card-skeleton"]')).toHaveCount(0);

    const width = await page.evaluate(() => {
        const main = document.querySelector('main');
        return {
            scrollWidth: document.documentElement.scrollWidth,
            innerWidth: window.innerWidth,
            mainScroll: main?.scrollWidth ?? 0,
            mainClient: main?.clientWidth ?? 0,
        };
    });
    expect(width.scrollWidth, `${route} scrolls sideways`).toBeLessThanOrEqual(width.innerWidth);
    // A mobile browser widens innerWidth to fit content that is too wide, so pin it to the viewport.
    expect(width.innerWidth, `${route} is zoomed out to fit wide content`).toBe(viewport.width);
    // <main> scrolls on its own (overflow: auto); it must not scroll sideways either.
    expect(width.mainScroll, `${route} main scrolls sideways`).toBeLessThanOrEqual(width.mainClient);

    // Nothing spills out of its own box, such as a long label out of a card or a button out of its bar.
    // Boxes that clip or scroll on purpose (overflow other than visible) are left alone.
    const spills = await page.evaluate(() =>
        Array.from(document.querySelectorAll<HTMLElement>('body *'))
            .filter((el) => {
                const style = getComputedStyle(el);
                return style.display !== 'inline' && style.overflowX === 'visible' && el.clientWidth > 0 && el.scrollWidth > el.clientWidth + 1;
            })
            .map((el) => `<${el.tagName.toLowerCase()}> "${(el.textContent ?? '').trim().slice(0, 40)}" ${el.scrollWidth} > ${el.clientWidth}`),
    );
    expect(spills, `${route} has content wider than its box`).toEqual([]);

    // Beside the sidebar (768 px and up) the header's domain select keeps a usable width.
    if (route === '/domains/example.org/mailboxes' && viewport.width >= 768) {
        const select = page.getByRole('combobox', { name: domainSelect });
        await expect(select).toBeVisible();
        const box = await select.boundingBox();
        expect(box?.width ?? 0, 'the domain select is squeezed').toBeGreaterThanOrEqual(120);
    }

    const violations = await page.evaluate(() => window.__csp);
    expect(violations, `${route} CSP violations`).toEqual([]);
}
