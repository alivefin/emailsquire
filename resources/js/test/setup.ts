import '@testing-library/jest-dom/vitest';
import { cleanup } from '@testing-library/react';
import { afterEach, beforeAll } from 'vitest';
import { clearStoredLocale, initI18n, setLocale } from '../i18n';

beforeAll(async () => {
    await initI18n('en');
});

// Vitest globals are off, so Testing Library's automatic cleanup does not register itself.
afterEach(async () => {
    cleanup();
    await setLocale('en');
    clearStoredLocale();
});
