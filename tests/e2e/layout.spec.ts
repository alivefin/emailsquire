import { test } from '@playwright/test';
import { checkLayout, expectNoCspConsoleErrors, MAIN_ROUTES, seed, signIn, watchCsp } from './support';

test.beforeAll(() => seed());

test('every main screen fits the width and breaks no CSP rule', async ({ page }) => {
    const consoleErrors = await watchCsp(page);

    await signIn(page);

    for (const route of MAIN_ROUTES) {
        await test.step(route, () => checkLayout(page, route, 'Domain'));
    }

    expectNoCspConsoleErrors(consoleErrors);
});
