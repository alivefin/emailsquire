import { describe, expect, it } from 'vitest';
import { inTimeZone } from '../test/timeZone';
import { dayAfterStart, dayStart, parseDay } from './dateRange';

describe('date range in Europe/Helsinki', () => {
    inTimeZone('Europe/Helsinki');

    it('runs in the fake time zone', () => {
        expect(new Date('2026-07-01T00:00:00Z').getTimezoneOffset()).toBe(-180);
    });

    it('starts a summer day at local midnight, +03:00', () => {
        expect(dayStart('2026-10-24')).toBe('2026-10-24T00:00:00+03:00');
        expect(dayAfterStart('2026-10-24')).toBe('2026-10-25T00:00:00+03:00');
    });

    it('spans the 25-hour day of the DST change on 2026-10-25', () => {
        const from = dayStart('2026-10-25');
        const to = dayAfterStart('2026-10-25');
        expect(from).toBe('2026-10-25T00:00:00+03:00');
        expect(to).toBe('2026-10-26T00:00:00+02:00');
        expect(Date.parse(to!) - Date.parse(from!)).toBe(25 * 3_600_000);
    });

    it('includes 23:30 local on the last chosen day and excludes 00:00 the day after', () => {
        const to = Date.parse(dayAfterStart('2026-10-25')!);
        // 23:30 EET (+02:00) on the 25th, and midnight starting the 26th.
        expect(Date.parse('2026-10-25T21:30:00Z')).toBeLessThan(to);
        expect(Date.parse('2026-10-25T22:00:00Z')).toBe(to);
        // `from` is inclusive: midnight starting the 25th is in, a minute before is out.
        const from = Date.parse(dayStart('2026-10-25')!);
        expect(Date.parse('2026-10-24T21:00:00Z')).toBe(from);
        expect(Date.parse('2026-10-24T20:59:00Z')).toBeLessThan(from);
    });

    it('starts a winter day at +02:00, across a month and a year end', () => {
        expect(dayStart('2026-12-31')).toBe('2026-12-31T00:00:00+02:00');
        expect(dayAfterStart('2026-12-31')).toBe('2027-01-01T00:00:00+02:00');
        expect(dayAfterStart('2026-10-31')).toBe('2026-11-01T00:00:00+02:00');
    });
});

describe('date range west of UTC', () => {
    inTimeZone('America/New_York');

    it('writes a negative offset', () => {
        expect(dayStart('2026-10-25')).toBe('2026-10-25T00:00:00-04:00');
        expect(dayAfterStart('2026-11-01')).toBe('2026-11-02T00:00:00-05:00');
    });
});

describe('parseDay', () => {
    it.each([
        ['2026-10-25', '2026-10-25'],
        ['2026-02-29', ''],
        ['2026-13-01', ''],
        ['2026-1-5', ''],
        ['25.10.2026', ''],
        ['', ''],
        [null, ''],
    ])('reads %s as "%s"', (raw, expected) => {
        expect(parseDay(raw)).toBe(expected);
    });

    it('gives no instant for a malformed day', () => {
        expect(dayStart('nope')).toBeUndefined();
        expect(dayAfterStart('')).toBeUndefined();
    });
});
