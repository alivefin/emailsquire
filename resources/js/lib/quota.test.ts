import { describe, expect, it } from 'vitest';
import { GIB, MAX_QUOTA, MIB, MIN_QUOTA, quotaProblem, toBytes } from './quota';

describe('quota constants', () => {
    it('match the API limits', () => {
        expect(MIB).toBe(1048576);
        expect(GIB).toBe(1073741824);
        expect(MIN_QUOTA).toBe(1048576);
        expect(MAX_QUOTA).toBe(1099511627776);
    });
});

describe('toBytes', () => {
    it('converts GB and MB as GiB and MiB', () => {
        expect(toBytes('5', 'GB')).toBe(5368709120);
        expect(toBytes('500', 'MB')).toBe(524288000);
    });

    it('accepts a dot or a comma as the decimal separator', () => {
        expect(toBytes('0,5', 'GB')).toBe(536870912);
        expect(toBytes('0.5', 'GB')).toBe(536870912);
        expect(toBytes(' 2 ', 'GB')).toBe(2 * GIB);
    });

    it.each(['', '  ', 'abc', '0', '0,0', '-1', '1e3', '1,2,3'])('returns null for %j', (v) => {
        expect(toBytes(v, 'MB')).toBeNull();
    });
});

describe('quotaProblem', () => {
    it('accepts the bounds', () => {
        expect(quotaProblem(toBytes('1024', 'GB'))).toBeNull();
        expect(toBytes('1024', 'GB')).toBe(MAX_QUOTA);
        expect(quotaProblem(MIN_QUOTA)).toBeNull();
    });

    it('explains each problem', () => {
        expect(quotaProblem(null)).toBe('Enter a size.');
        expect(quotaProblem(toBytes('0.5', 'MB'))).toBe('At least 1 MB.');
        expect(quotaProblem(toBytes('1025', 'GB'))).toBe('At most 1024 GB.');
    });

    it('refuses a value below the space already used', () => {
        expect(quotaProblem(1 * GIB, 2 * GIB)).toBe("Can't be lower than the space already used (2 GB).");
        expect(quotaProblem(2 * GIB, 2 * GIB)).toBeNull();
        expect(quotaProblem(1 * GIB, null)).toBeNull();
    });
});
