import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import i18next from 'i18next';
import { currentLocale, detectLocale, setLocale } from './index';

function setNavigator(language: string) {
    vi.spyOn(window.navigator, 'language', 'get').mockReturnValue(language);
}

beforeEach(() => {
    window.localStorage.clear();
});
afterEach(() => {
    vi.restoreAllMocks();
});

describe('detectLocale', () => {
    it('returns fi for a Finnish browser with nothing stored', () => {
        setNavigator('fi-FI');
        expect(detectLocale()).toBe('fi');
    });

    it('returns en for other browser languages', () => {
        setNavigator('sv-SE');
        expect(detectLocale()).toBe('en');
    });

    it('prefers a stored language over the browser', () => {
        setNavigator('fi-FI');
        window.localStorage.setItem('emailsquire.locale', 'en');
        expect(detectLocale()).toBe('en');
    });

    it('ignores stored values other than en and fi', () => {
        setNavigator('fi-FI');
        window.localStorage.setItem('emailsquire.locale', 'sv');
        expect(detectLocale()).toBe('fi');
    });

    it('falls back to the browser when localStorage throws', () => {
        setNavigator('fi-FI');
        vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
            throw new Error('blocked');
        });
        expect(detectLocale()).toBe('fi');
    });
});

describe('setLocale', () => {
    it('sets the html lang, i18next language and storage', async () => {
        await setLocale('fi');
        expect(document.documentElement.lang).toBe('fi');
        expect(i18next.language).toBe('fi');
        expect(currentLocale()).toBe('fi');
        expect(window.localStorage.getItem('emailsquire.locale')).toBe('fi');
    });

    it('still switches when localStorage throws', async () => {
        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
            throw new Error('blocked');
        });
        await setLocale('fi');
        expect(currentLocale()).toBe('fi');
    });
});
