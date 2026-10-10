import { expect, test } from '@playwright/test';
import { seed, signIn } from './support';

const ROUTES = ['/', '/domains', '/domains/example.org/mailboxes', '/domains/example.org/forwarding', '/addresses', '/blocked'];

declare global {
    interface Window {
        __csp: string[];
    }
}

test.beforeAll(() => seed());

test('every main screen fits the width and breaks no CSP rule', async ({ page }) => {
    const viewport = page.viewportSize();
    if (viewport === null) throw new Error('The project sets no viewport.');
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

    await signIn(page);

    for (const route of ROUTES) {
        await test.step(route, async () => {
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

            // Beside the sidebar (768 px and up) the header's domain select keeps a usable width.
            if (route === '/domains/example.org/mailboxes' && viewport.width >= 768) {
                const select = page.getByRole('combobox', { name: 'Domain' });
                await expect(select).toBeVisible();
                const box = await select.boundingBox();
                expect(box?.width ?? 0, 'the domain select is squeezed').toBeGreaterThanOrEqual(120);
            }

            const violations = await page.evaluate(() => window.__csp);
            expect(violations, `${route} CSP violations`).toEqual([]);
        });
    }

    const cspErrors = consoleErrors.filter((t) => /content security policy|CSP/i.test(t));
    expect(cspErrors).toEqual([]);
});
