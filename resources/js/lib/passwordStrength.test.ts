import { describe, expect, it } from 'vitest';
import { scorePassword } from './passwordStrength';

describe('scorePassword', () => {
    // Expected values verified against App\MailServer\PasswordStrength::score via php -r.
    it.each([
        ['', 0],
        ['abc', 0],
        ['abcdefg1', 1],
        ['abcdefghijk1', 2],
        ['abcdefghijk1!', 3],
        ['abcdefghijklmno1!', 4],
        ['1234567!', 1],
        ['ääääääääääää', 2],
    ])('scores %j as %i', (pw, expected) => {
        expect(scorePassword(pw)).toBe(expected);
    });

    it('counts code points, not UTF-16 units', () => {
        // 12 emoji are 24 UTF-16 units but 12 code points: length point + special character.
        expect(scorePassword('😀'.repeat(12))).toBe(2);
        // 7 emoji are 14 units but only 7 code points: capped at 1.
        expect(scorePassword('😀'.repeat(7))).toBe(1);
    });
});
