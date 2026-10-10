import i18next from 'i18next';
import { initReactI18next } from 'react-i18next';
import { useSyncExternalStore } from 'react';

export type Locale = 'en' | 'fi';

const STORAGE_KEY = 'emailsquire.locale';

// Every catalogue file registers itself: the namespace is the file name, the language is the folder.
const catalogues = import.meta.glob<Record<string, unknown>>('./{en,fi}/*.json', { eager: true, import: 'default' });

const resources: Record<Locale, Record<string, Record<string, unknown>>> = { en: {}, fi: {} };
for (const [path, content] of Object.entries(catalogues)) {
    const match = /^\.\/(en|fi)\/([^/]+)\.json$/.exec(path);
    if (match) resources[match[1] as Locale][match[2]!] = content;
}
const namespaces = Object.keys(resources.en);

export function isLocale(value: unknown): value is Locale {
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
    const language = typeof navigator === 'undefined' ? undefined : (navigator.language as string | undefined);
    // `fi` or `fi-*` only: `fil` (Filipino) and `sv-FI` are not Finnish. The server applies the same rule.
    return /^fi(?:[-_]|$)/i.test(language ?? '') ? 'fi' : 'en';
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
        ns: namespaces,
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
