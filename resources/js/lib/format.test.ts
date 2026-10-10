import { describe, expect, it } from 'vitest';
import { formatBytes, formatRelative } from './format';

describe('formatBytes', () => {
    it.each([
        [5368709120, '5 GB'],
        [524288000, '500 MB'],
        [1610612736, '1.5 GB'],
        [1073741824, '1 GB'],
        [1048576, '1 MB'],
        [1048575, '1024 KB'],
        [2048, '2 KB'],
        [0, '0 KB'],
    ])('formats %i as %s', (bytes, expected) => {
        expect(formatBytes(bytes)).toBe(expected);
    });
});

describe('formatRelative', () => {
    const now = new Date(2026, 9, 10, 12, 0, 0);
    const ago = (ms: number) => new Date(now.getTime() - ms).toISOString();

    it('gives just now under a minute', () => {
        expect(formatRelative(ago(30_000), now)).toBe('just now');
    });

    it('gives minutes', () => {
        expect(formatRelative(ago(5 * 60_000), now)).toBe('5 min ago');
    });

    it('gives hours', () => {
        expect(formatRelative(ago(3 * 3_600_000), now)).toBe('3 h ago');
    });

    it('gives yesterday for the previous calendar day', () => {
        expect(formatRelative(new Date(2026, 9, 9, 23, 0).toISOString(), now)).toBe('yesterday');
    });

    it('gives a month and day for older dates', () => {
        expect(formatRelative(new Date(2026, 9, 3, 9, 0).toISOString(), now)).toBe('Oct 3');
    });
});
