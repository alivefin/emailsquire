import { describe, expect, it } from 'vitest';
import { storageLevel } from './storage';

describe('storageLevel', () => {
    const limit = 10000;

    it('is off when disabled or unknown', () => {
        expect(storageLevel(5000, limit, true)).toBe('off');
        expect(storageLevel(null, limit, false)).toBe('off');
    });

    it('switches to warn at 80 percent', () => {
        expect(storageLevel(7999, limit, false)).toBe('ok');
        expect(storageLevel(8000, limit, false)).toBe('warn');
    });

    it('switches to crit at 95 percent', () => {
        expect(storageLevel(9499, limit, false)).toBe('warn');
        expect(storageLevel(9500, limit, false)).toBe('crit');
        expect(storageLevel(20000, limit, false)).toBe('crit');
    });
});
