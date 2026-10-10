import { formatBytes } from './format';

export const MIB = 1048576;
export const GIB = 1073741824;
export const MIN_QUOTA = MIB;
export const MAX_QUOTA = 1099511627776;

export type QuotaUnit = 'MB' | 'GB';

/** "GB" means GiB and "MB" means MiB. Returns null for empty, non-numeric or non-positive input. */
export function toBytes(value: string, unit: QuotaUnit): number | null {
    const text = value.trim().replace(',', '.');
    if (!/^(\d+\.?\d*|\.\d+)$/.test(text)) {
        return null;
    }
    const n = Number(text);
    if (!(n > 0)) {
        return null;
    }
    return Math.round(n * (unit === 'GB' ? GIB : MIB));
}

export function quotaProblem(bytes: number | null, usedBytes?: number | null): string | null {
    if (bytes === null) return 'Enter a size.';
    if (bytes < MIN_QUOTA) return 'At least 1 MB.';
    if (bytes > MAX_QUOTA) return 'At most 1024 GB.';
    if (usedBytes != null && bytes < usedBytes) {
        return `Can't be lower than the space already used (${formatBytes(usedBytes)}).`;
    }
    return null;
}
