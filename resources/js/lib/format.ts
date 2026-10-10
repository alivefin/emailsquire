import i18next from 'i18next';
import { currentLocale, type Locale } from '../i18n';

// Plain literals instead of importing quota.ts, which imports formatBytes from here.
const MIB = 1048576;
const GIB = 1073741824;

const UNITS: Record<Locale, { gb: string; mb: string; kb: string }> = {
    en: { gb: 'GB', mb: 'MB', kb: 'KB' },
    fi: { gb: 'Gt', mb: 'Mt', kb: 'kt' },
};

function trim(n: number, digits: number, locale: Locale): string {
    const text = n.toFixed(digits).replace(/\.0+$/, '');
    return locale === 'fi' ? text.replace('.', ',') : text;
}

/** Binary sizes with `GB`/`MB`/`KB` in English and `Gt`/`Mt`/`kt` in Finnish, using the locale's decimal separator. */
export function formatBytes(bytes: number, locale: Locale = currentLocale()): string {
    const unit = UNITS[locale];
    if (bytes >= GIB) return `${trim(bytes / GIB, 1, locale)} ${unit.gb}`;
    if (bytes >= MIB) return `${Math.round(bytes / MIB)} ${unit.mb}`;
    return `${Math.round(bytes / 1024)} ${unit.kb}`;
}

function startOfDay(d: Date): number {
    return new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime();
}

/** Whole calendar days from `then` to `now`. Compares dates, not 24-hour spans, so a 23- or 25-hour DST day still counts as one. */
function calendarDaysBetween(then: Date, now: Date): number {
    const a = Date.UTC(then.getFullYear(), then.getMonth(), then.getDate());
    const b = Date.UTC(now.getFullYear(), now.getMonth(), now.getDate());
    return Math.round((b - a) / 86_400_000);
}

export function formatRelative(iso: string, now: Date = new Date(), locale: Locale = currentLocale()): string {
    const then = new Date(iso);
    const diff = now.getTime() - then.getTime();
    const minutes = Math.floor(diff / 60_000);
    const t = (key: string, options: Record<string, unknown> = {}) => i18next.t(key, { ...options, lng: locale, ns: 'common' });

    if (minutes < 1) return t('relative.justNow');
    if (minutes < 60) return t('relative.minutesAgo', { minutes });
    const hours = Math.floor(minutes / 60);
    if (hours < 24 && startOfDay(then) === startOfDay(now)) return t('relative.hoursAgo', { hours });
    if (calendarDaysBetween(then, now) === 1) return t('relative.yesterday');

    // Finnish writes the numeric day and month ("3.10."), English the short month name ("Oct 3").
    const style: Intl.DateTimeFormatOptions = locale === 'fi' ? { day: 'numeric', month: 'numeric' } : { month: 'short', day: 'numeric' };
    return new Intl.DateTimeFormat(locale, style).format(then);
}

/** A full date and time in the locale's own format. */
export function formatDate(iso: string, locale: Locale = currentLocale()): string {
    return new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso));
}
