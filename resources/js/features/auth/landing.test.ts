import { describe, expect, it } from 'vitest';
import { afterPortalSignInPath, afterSignInPath } from './landing';

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

    it('ignores a next in the mailbox portal: a console session never lands there', () => {
        expect(afterSignInPath({ landing: 'example.org' }, '/my/password')).toBe('/domains/example.org/mailboxes');
        expect(afterSignInPath(null, '/my')).toBe('/');
        expect(afterSignInPath(null, '/my?x=1')).toBe('/');
        // Only the /my area itself: a page whose name starts with "my" is not in it.
        expect(afterSignInPath(null, '/mystery')).toBe('/mystery');
    });
});

describe('afterPortalSignInPath', () => {
    it.each([
        [null, '/my'],
        ['/', '/my'],
        ['/my', '/my'],
        ['/my/password', '/my/password'],
        ['/my/settings?x=1', '/my/settings?x=1'],
        ['/domains/example.org/mailboxes', '/my'],
        ['/mystery', '/my'],
        ['//evil.example/my', '/my'],
        ['/my\\evil', '/my'],
    ])('sends a mailbox owner with next %j to %j', (next, expected) => {
        expect(afterPortalSignInPath(next)).toBe(expected);
    });
});
