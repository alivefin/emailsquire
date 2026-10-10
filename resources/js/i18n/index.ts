import i18next from 'i18next';
import { initReactI18next } from 'react-i18next';
import { useSyncExternalStore } from 'react';
import enCommon from './en/common.json';
import fiCommon from './fi/common.json';

export type Locale = 'en' | 'fi';

const STORAGE_KEY = 'emailsquire.locale';

const resources = {
    en: { common: enCommon },
    fi: { common: fiCommon },
};

function isLocale(value: unknown): value is Locale {
    return value === 'en' || value === 'fi';
}

/** The stored choice if valid, otherwise the browser language (`fi*` is Finnish), otherwise English. */
export function detectLocale(): Locale {
    try {
        const stored = window.localStorage.getItem(STORAGE_KEY);
        if (isLocale(stored)) return stored;
    } catch {
        // Storage blocked: fall through to the browser language.
    }
    const language = typeof navigator === 'undefined' ? '' : navigator.language;
    return language.toLowerCase().startsWith('fi') ? 'fi' : 'en';
}

export async function initI18n(locale: Locale): Promise<void> {
    if (i18next.isInitialized) {
        await setLocale(locale);
        return;
    }
    await i18next.use(initReactI18next).init({
        resources,
        lng: locale,
        fallbackLng: 'en',
        supportedLngs: ['en', 'fi'],
        defaultNS: 'common',
        ns: ['common'],
        // React escapes text nodes itself, and dangerouslySetInnerHTML is banned by ESLint.
        interpolation: { escapeValue: false },
        react: { useSuspense: false },
    });
    document.documentElement.lang = locale;
}

export function currentLocale(): Locale {
    return isLocale(i18next.language) ? i18next.language : 'en';
}

export async function setLocale(locale: Locale): Promise<void> {
    await i18next.changeLanguage(locale);
    document.documentElement.lang = locale;
    try {
        window.localStorage.setItem(STORAGE_KEY, locale);
    } catch {
        // Storage is only a convenience.
    }
}

/** Forget the stored choice. Used by tests to reset between cases. */
export function clearStoredLocale(): void {
    try {
        window.localStorage.removeItem(STORAGE_KEY);
    } catch {
        // Nothing to clear.
    }
}

function subscribe(onChange: () => void): () => void {
    i18next.on('languageChanged', onChange);
    return () => {
        i18next.off('languageChanged', onChange);
    };
}

/** The current UI language; re-renders when it changes. */
export function useLocale(): Locale {
    return useSyncExternalStore(subscribe, currentLocale, () => 'en');
}
