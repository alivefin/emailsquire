import { describe, expect, it } from 'vitest';
import { afterSignInPath } from './landing';

describe('afterSignInPath', () => {
    it.each([
        ['example.org', '/domains/example.org/mailboxes'],
        ['xn--bcher-kva.example', '/domains/xn--bcher-kva.example/mailboxes'],
        [null, '/'],
        ['', '/'],
        ['..', '/'],
        ['.', '/'],
        ['../profile', '/'],
        ['example.org/../../profile', '/'],
        ['Example.ORG', '/'],
        ['localhost', '/'],
        ['example.org?x=1', '/'],
    ])('lands %j on %j when there is no next', (landing, expected) => {
        expect(afterSignInPath({ landing })).toBe(expected);
    });

    it('prefers a next that names a page', () => {
        expect(afterSignInPath({ landing: 'example.org' }, '/profile')).toBe('/profile');
        expect(afterSignInPath({ landing: 'example.org' }, '/')).toBe('/domains/example.org/mailboxes');
        expect(afterSignInPath(null, null)).toBe('/');
    });
});
