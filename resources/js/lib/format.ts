import i18next from 'i18next';
import { currentLocale, type Locale } from '../i18n';

// Plain literals instead of importing quota.ts, which imports formatBytes from here.
const MIB = 1048576;
const GIB = 1073741824;
const TIB = 1099511627776;
const PIB = 1125899906842624;

const UNITS: Record<Locale, { pb: string; tb: string; gb: string; mb: string; kb: string }> = {
    en: { pb: 'PB', tb: 'TB', gb: 'GB', mb: 'MB', kb: 'KB' },
    fi: { pb: 'Pt', tb: 'Tt', gb: 'Gt', mb: 'Mt', kb: 'kt' },
};

function trim(n: number, digits: number, locale: Locale): string {
    const text = n.toFixed(digits).replace(/\.0+$/, '');
    return locale === 'fi' ? text.replace('.', ',') : text;
}

/**
 * Binary sizes with `PB`/`TB`/`GB`/`MB`/`KB` in English and `Pt`/`Tt`/`Gt`/`Mt`/`kt` in Finnish, using the
 * locale's decimal separator. TB starts at 1 TiB, so mailbox sizes (at most 1 TiB) read as before.
 */
export function formatBytes(bytes: number, locale: Locale = currentLocale()): string {
    const unit = UNITS[locale];
    if (bytes >= PIB) return `${trim(bytes / PIB, 1, locale)} ${unit.pb}`;
    if (bytes >= TIB) return `${trim(bytes / TIB, 1, locale)} ${unit.tb}`;
    if (bytes >= GIB) return `${trim(bytes / GIB, 1, locale)} ${unit.gb}`;
    if (bytes >= MIB) return `${Math.round(bytes / MIB)} ${unit.mb}`;
    return `${Math.round(bytes / 1024)} ${unit.kb}`;
}

/** "48 / 100 GB": a part of a whole, the unit written once when both share it ("512 MB / 100 GB" otherwise). */
export function formatBytesOf(part: number, whole: number, locale: Locale = currentLocale()): string {
    const a = formatBytes(part, locale);
    const b = formatBytes(whole, locale);
    const split = (text: string) => [text.slice(0, text.lastIndexOf(' ')), text.slice(text.lastIndexOf(' ') + 1)] as const;
    const [number, unit] = split(a);
    return unit === split(b)[1] ? `${number} / ${b}` : `${a} / ${b}`;
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

/** A date without the time in the locale's own format ("Oct 8, 2026", "8.10.2026"). */
export function formatDay(iso: string, locale: Locale = currentLocale()): string {
    return new Intl.DateTimeFormat(locale, { dateStyle: 'medium' }).format(new Date(iso));
}

/** A full date and time in the locale's own format. */
export function formatDate(iso: string, locale: Locale = currentLocale()): string {
    return new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso));
}
