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

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

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
    const fi = locale === 'fi';

    if (minutes < 1) return fi ? 'juuri nyt' : 'just now';
    if (minutes < 60) return fi ? `${minutes} min sitten` : `${minutes} min ago`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24 && startOfDay(then) === startOfDay(now)) return fi ? `${hours} t sitten` : `${hours} h ago`;
    if (calendarDaysBetween(then, now) === 1) return fi ? 'eilen' : 'yesterday';

    if (fi) return new Intl.DateTimeFormat('fi', { day: 'numeric', month: 'numeric' }).format(then);
    return `${MONTHS[then.getMonth()] ?? ''} ${then.getDate()}`;
}

/** A full date and time in the locale's own format. */
export function formatDate(iso: string, locale: Locale = currentLocale()): string {
    return new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso));
}
