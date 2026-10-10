import { execFileSync } from 'node:child_process';
import { expect, type Locator, type Page } from '@playwright/test';

export const ADMIN = { email: 'admin@example.org', password: 'Summersun-e2e-2026!' };

/** The second admin `seed.php` creates, with `users.locale = 'fi'` saved on the server. */
export const FI_ADMIN = { email: 'fi-admin@example.org', password: ADMIN.password };

export type Locale = 'en' | 'fi';

/** The sign-in form's labels per language. */
const SIGN_IN_FORM: Record<Locale, { email: string; password: string; submit: string }> = {
    en: { email: 'Email', password: 'Password', submit: 'Continue' },
    fi: { email: 'Sähköposti', password: 'Salasana', submit: 'Jatka' },
};

/** The console's main routes: the layout checks run on each of them. */
export const MAIN_ROUTES = ['/', '/domains', '/domains/example.org/mailboxes', '/domains/example.org/forwarding', '/addresses', '/blocked'];

declare global {
    interface Window {
        __csp: string[];
    }
}

/**
 * Resets both e2e schemas and the admin. Every spec calls it in beforeAll: the data-changing specs
 * need a clean fixture, and the reset also empties the login limiter (five sign-ins a minute).
 */
export function seed(): void {
    const out = execFileSync('php', ['tests/e2e/seed.php'], { encoding: 'utf8' });
    if (!out.includes('seeded')) throw new Error(`seed.php did not finish: ${out}`);
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
