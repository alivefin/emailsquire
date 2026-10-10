import { afterAll, beforeAll, describe, expect, it, vi } from 'vitest';
import { setLocale } from '../i18n';
import { formatBytes, formatBytesOf, formatDate, formatRelative } from './format';

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

describe('formatBytesOf', () => {
    const GIB = 1073741824;
    it('writes a shared unit once', () => {
        expect(formatBytesOf(48 * GIB, 100 * GIB, 'en')).toBe('48 / 100 GB');
        expect(formatBytesOf(1.5 * GIB, 100 * GIB, 'fi')).toBe('1,5 / 100 Gt');
    });

    it('keeps both units when they differ', () => {
        expect(formatBytesOf(512 * 1048576, 100 * GIB, 'en')).toBe('512 MB / 100 GB');
    });
});

describe('formatBytes in Finnish', () => {
    it.each([
        [5368709120, '5 Gt'],
        [4509715660, '4,2 Gt'],
        [524288000, '500 Mt'],
        [2048, '2 kt'],
    ])('formats %i as %s', (bytes, expected) => {
        expect(formatBytes(bytes, 'fi')).toBe(expected);
    });

    it('defaults to the current locale', async () => {
        await setLocale('fi');
        expect(formatBytes(4509715660)).toBe('4,2 Gt');
        expect(formatBytes(4509715660, 'en')).toBe('4.2 GB');
    });
});

describe('formatRelative in Finnish', () => {
    const now = new Date(2026, 9, 10, 12, 0, 0);
    const ago = (ms: number) => new Date(now.getTime() - ms).toISOString();

    it.each([
        ['just now', ago(30_000), 'juuri nyt'],
        ['minutes', ago(5 * 60_000), '5 min sitten'],
        ['hours', ago(3 * 3_600_000), '3 t sitten'],
        ['yesterday', new Date(2026, 9, 9, 23, 0).toISOString(), 'eilen'],
        ['older', new Date(2026, 9, 3, 9, 0).toISOString(), '3.10.'],
    ])('%s', (_name, iso, expected) => {
        expect(formatRelative(iso, now, 'fi')).toBe(expected);
    });

    it('defaults to the current locale', async () => {
        await setLocale('fi');
        expect(formatRelative(ago(5 * 60_000), now)).toBe('5 min sitten');
    });
});

describe('formatRelative across the autumn clock change', () => {
    beforeAll(() => {
        // Finland's clocks go back on Sunday 25 October 2026, so that calendar day is 25 hours long.
        vi.stubEnv('TZ', 'Europe/Helsinki');
    });
    afterAll(() => {
        vi.unstubAllEnvs();
    });

    it('calls the previous day yesterday even when it lasted 25 hours', () => {
        expect(new Date(2026, 9, 26).getTime() - new Date(2026, 9, 25).getTime()).toBe(25 * 3_600_000);
        const now = new Date(2026, 9, 26, 9, 0, 0);
        const then = new Date(2026, 9, 25, 12, 0, 0).toISOString();
        expect(formatRelative(then, now, 'en')).toBe('yesterday');
        expect(formatRelative(then, now, 'fi')).toBe('eilen');
    });

    it('does not call two calendar days ago yesterday', () => {
        const now = new Date(2026, 9, 26, 9, 0, 0);
        expect(formatRelative(new Date(2026, 9, 24, 23, 30, 0).toISOString(), now, 'fi')).toBe('24.10.');
    });
});

describe('formatDate', () => {
    it('gives a medium date and short time per locale', () => {
        const iso = new Date(2026, 9, 3, 9, 5).toISOString();
        expect(formatDate(iso, 'en')).toBe(new Intl.DateTimeFormat('en', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso)));
        expect(formatDate(iso, 'fi')).toBe(new Intl.DateTimeFormat('fi', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso)));
        expect(formatDate(iso, 'fi')).toContain('3.10.2026');
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
