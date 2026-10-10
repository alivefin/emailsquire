export type StorageLevel = 'off' | 'ok' | 'warn' | 'crit';

export function storageLevel(used: number | null, limit: number, disabled: boolean): StorageLevel {
    if (disabled || used === null) return 'off';
    const ratio = limit > 0 ? used / limit : 0;
    if (ratio >= 0.95) return 'crit';
    if (ratio >= 0.8) return 'warn';
    return 'ok';
}
