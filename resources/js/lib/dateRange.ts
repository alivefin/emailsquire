const DAY = /^(\d{4})-(\d{2})-(\d{2})$/;

/** Local midnight starting `day` (a `YYYY-MM-DD` date input value) plus `addDays`; null when `day` is not a real date. */
function localMidnight(day: string, addDays: number): Date | null {
    const m = DAY.exec(day);
    if (!m) return null;
    const [y, mo, d] = [Number(m[1]), Number(m[2]) - 1, Number(m[3])];
    const date = new Date(y, mo, d);
    if (date.getFullYear() !== y || date.getMonth() !== mo || date.getDate() !== d) return null;
    // Through the Date constructor, so a DST change between the two days moves the offset, not the hour.
    return addDays === 0 ? date : new Date(y, mo, d + addDays);
}

const pad = (n: number) => String(n).padStart(2, '0');

/** `2026-10-25T00:00:00+03:00`: the local wall time with the browser's offset at that instant. */
export function isoWithOffset(date: Date): string {
    const offset = -date.getTimezoneOffset();
    const sign = offset < 0 ? '-' : '+';
    const abs = Math.abs(offset);
    const wall = `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
    return `${wall}${sign}${pad(Math.floor(abs / 60))}:${pad(abs % 60)}`;
}

/** A date input's value when it is a real `YYYY-MM-DD` date, else ''. */
export function parseDay(raw: string | null | undefined): string {
    return raw && localMidnight(raw, 0) ? raw : '';
}

/** The activity log's `from`: the start of `day` in the browser's time zone (inclusive). */
export function dayStart(day: string): string | undefined {
    const date = localMidnight(day, 0);
    return date ? isoWithOffset(date) : undefined;
}

/** The activity log's `to`: the start of the day after `day` in the browser's time zone (exclusive). */
export function dayAfterStart(day: string): string | undefined {
    const date = localMidnight(day, 1);
    return date ? isoWithOffset(date) : undefined;
}
