import { describe, expect, it } from 'vitest';
import { activityText, actorLabel } from './activityText';

const cases: [string, string, string][] = [
    ['domain.create', 'example.org', 'added domain example.org'],
    ['domain.delete', 'example.org', 'deleted domain example.org'],
    ['mailbox.create', 'anna@example.org', 'created mailbox anna@example.org'],
    ['mailbox.update', 'anna@example.org', 'changed mailbox anna@example.org'],
    ['mailbox.password_reset', 'anna@example.org', 'reset the password of anna@example.org'],
    ['mailbox.disable', 'anna@example.org', 'disabled mailbox anna@example.org'],
    ['mailbox.enable', 'anna@example.org', 'enabled mailbox anna@example.org'],
    ['mailbox.delete', 'anna@example.org', 'deleted mailbox anna@example.org'],
    ['forwarding.replace', 'info@example.org', 'set forwarding for info@example.org'],
    ['forwarding.delete', 'info@example.org', 'removed forwarding for info@example.org'],
    ['blocked.create', 'spam@example.org', 'blocked spam@example.org'],
    ['blocked.delete', 'spam@example.org', 'unblocked spam@example.org'],
    ['admin.create', 'bob@example.org', 'issued a setup link for bob@example.org'],
    ['admin.setup', 'bob@example.org', 'set the password for bob@example.org'],
    ['maildir.restore', 'anna@example.org', 'queued a mail restore for anna@example.org'],
    ['maildir.retry', '42', 'retried a maildir task (#42)'],
    ['user.password_changed', 'anna@example.org', 'changed the password of anna@example.org'],
    ['user.password_reset', 'anna@example.org', 'reset the password of anna@example.org with a reset link'],
    ['user.two_factor_enabled', 'anna@example.org', 'turned on two-factor authentication for anna@example.org'],
    ['user.two_factor_disabled', 'anna@example.org', 'turned off two-factor authentication for anna@example.org'],
    ['user.recovery_codes_regenerated', 'anna@example.org', 'created new recovery codes for anna@example.org'],
    ['user.sessions_revoked', 'anna@example.org', 'signed anna@example.org out everywhere else'],
];

describe('activityText', () => {
    it.each(cases)('describes %s', (action, object_key, expected) => {
        expect(activityText({ action, object_key })).toBe(expected);
    });

    it('falls back to the raw action and key for an unknown action', () => {
        expect(activityText({ action: 'thing.explode', object_key: 'x' })).toBe('thing.explode x');
    });

    it.each(cases)('describes %s the same way when you acted', (action, object_key, expected) => {
        expect(activityText({ action, object_key, actor_kind: 'you' })).toBe(expected);
    });

    it.each([
        ['you', 'Someone', 'You'],
        ['user', 'Bob Other', 'Bob Other'],
        ['deleted_user', 'Deleted user', 'Deleted user'],
        ['cli', 'Command line', 'Command line'],
        ['system', 'System', 'System'],
    ] as const)('labels the %s actor', (actor_kind, actor_name, expected) => {
        expect(actorLabel({ actor_kind, actor_name })).toBe(expected);
    });
});
