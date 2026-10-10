// Plain literals instead of importing quota.ts, which imports formatBytes from here.
const MIB = 1048576;
const GIB = 1073741824;

function trim(n: number, digits: number): string {
    return n.toFixed(digits).replace(/\.0+$/, '');
}

export function formatBytes(bytes: number): string {
    if (bytes >= GIB) return `${trim(bytes / GIB, 1)} GB`;
    if (bytes >= MIB) return `${Math.round(bytes / MIB)} MB`;
    return `${Math.round(bytes / 1024)} KB`;
}

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

function startOfDay(d: Date): number {
    return new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime();
}

export function formatRelative(iso: string, now: Date = new Date()): string {
    const then = new Date(iso);
    const diff = now.getTime() - then.getTime();
    const minutes = Math.floor(diff / 60_000);

    if (minutes < 1) return 'just now';
    if (minutes < 60) return `${minutes} min ago`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24 && startOfDay(then) === startOfDay(now)) return `${hours} h ago`;
    if (startOfDay(now) - startOfDay(then) === 86_400_000) return 'yesterday';

    return `${MONTHS[then.getMonth()] ?? ''} ${then.getDate()}`;
}
