import { describe, expect, it } from 'vitest';
import { canManageDomains, canShare, canWrite, isServerAdmin } from './roles';

const me = (over: Partial<{ is_server_admin: boolean; managers_can_share: boolean }> = {}) => ({
    is_server_admin: false,
    managers_can_share: false,
    ...over,
});

describe('canWrite', () => {
    it.each([
        ['admin', true],
        ['manager', true],
        ['viewer', false],
        [undefined, false],
        [null, false],
    ] as const)('is %s → %s', (role, expected) => {
        expect(canWrite(role)).toBe(expected);
    });
});

describe('canShare', () => {
    it('lets an admin share whatever the setting says', () => {
        expect(canShare('admin', me({ is_server_admin: true }))).toBe(true);
        expect(canShare('admin', me({ is_server_admin: true, managers_can_share: true }))).toBe(true);
    });

    it('lets a manager share only when managers_can_share is on', () => {
        expect(canShare('manager', me())).toBe(false);
        expect(canShare('manager', me({ managers_can_share: true }))).toBe(true);
    });

    it('never lets a viewer share, nor anyone before the role or me is known', () => {
        expect(canShare('viewer', me({ managers_can_share: true }))).toBe(false);
        expect(canShare(undefined, me({ managers_can_share: true }))).toBe(false);
        expect(canShare('manager', undefined)).toBe(false);
    });
});

describe('canManageDomains and isServerAdmin', () => {
    it('follow is_server_admin', () => {
        expect(canManageDomains(me({ is_server_admin: true }))).toBe(true);
        expect(canManageDomains(me())).toBe(false);
        expect(canManageDomains(null)).toBe(false);
        expect(isServerAdmin(me({ is_server_admin: true }))).toBe(true);
        expect(isServerAdmin(undefined)).toBe(false);
    });
});
