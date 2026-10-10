import { describe, expect, it } from 'vitest';
import { sanitizeCode } from './otp';

describe('sanitizeCode', () => {
    it.each([
        ['123456', '123456'],
        ['123 456', '123456'],
        ['123-456', '123456'],
        ['12a34b56c7', '123456'],
        ['', ''],
    ])('%j becomes %j', (raw, out) => {
        expect(sanitizeCode(raw)).toBe(out);
    });
});
