import { describe, expect, it } from 'vitest';
import { PASSWORD_ALPHABET, generatePassword } from './password';
import { scorePassword } from './passwordStrength';

describe('generatePassword', () => {
    it('uses the backend alphabet', () => {
        expect(PASSWORD_ALPHABET).toBe('abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789-_!');
    });

    it('returns 16 characters from the alphabet that score at least 3, 1000 times', () => {
        for (let i = 0; i < 1000; i++) {
            const pw = generatePassword();
            expect(pw).toHaveLength(16);
            for (const ch of pw) {
                expect(PASSWORD_ALPHABET).toContain(ch);
            }
            expect(scorePassword(pw)).toBeGreaterThanOrEqual(3);
        }
    });
});
