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
    ['user.passkey_added', 'anna@example.org', 'added a passkey for anna@example.org'],
    ['user.passkey_removed', 'anna@example.org', 'removed a passkey from anna@example.org'],
    ['user.passkey_renamed', 'anna@example.org', "renamed one of anna@example.org's passkeys"],
    ['user.sessions_revoked', 'anna@example.org', 'signed anna@example.org out everywhere else'],
    // The actor is the mailbox itself (actor_kind `mailbox`), so the sentence needs no object.
    ['portal.password_changed', 'anna@example.org', 'changed their mailbox password'],
    ['portal.forwarding_changed', 'anna@example.org', 'changed the forwarding of their mailbox'],
    // The object is the alias; the mailbox it delivers to is the actor.
    ['portal.alias_created', 'sales@example.org', 'added the alias sales@example.org to their mailbox'],
    ['portal.alias_deleted', 'sales@example.org', 'removed the alias sales@example.org from their mailbox'],
    ['domain.dns_checked', 'example.org', 'checked the DNS records of example.org'],
];

const B = 'bob@example.org';
const D = 'example.org';

/** The 2c actions, as the backend writes them (object type, key, domain and `changes`). */
const SHARING: [string, { action: string; object_key: string; domain_name: string | null; changes: Record<string, unknown> | null }, string][] = [
    ['user.invited to a domain', { action: 'user.invited', object_key: B, domain_name: D, changes: { role: 'manager' } }, 'invited bob@example.org to example.org as manager'],
    ['user.invited as server admin', { action: 'user.invited', object_key: B, domain_name: null, changes: { role: 'server_admin' } }, 'invited bob@example.org to be a server admin'],
    ['invitation.resent', { action: 'invitation.resent', object_key: B, domain_name: D, changes: { role: 'viewer' } }, 'resent the invitation to bob@example.org'],
    ['invitation.revoked', { action: 'invitation.revoked', object_key: B, domain_name: D, changes: { role: 'viewer' } }, 'revoked the invitation to bob@example.org'],
    ['invitation.accepted to a domain', { action: 'invitation.accepted', object_key: B, domain_name: D, changes: { role: 'viewer' } }, 'accepted the invitation to example.org as viewer'],
    ['invitation.accepted as server admin', { action: 'invitation.accepted', object_key: B, domain_name: null, changes: { role: 'server_admin' } }, 'accepted the invitation to be a server admin'],
    ['grant.created', { action: 'grant.created', object_key: B, domain_name: D, changes: { role: 'viewer' } }, 'gave bob@example.org access to example.org as viewer'],
    ['grant.changed', { action: 'grant.changed', object_key: B, domain_name: D, changes: { role: ['viewer', 'manager'] } }, 'changed the role of bob@example.org on example.org from viewer to manager'],
    ['grant.removed', { action: 'grant.removed', object_key: B, domain_name: D, changes: { role: 'manager' } }, 'removed bob@example.org from example.org (manager)'],
    ['user.role_changed up', { action: 'user.role_changed', object_key: B, domain_name: null, changes: { is_server_admin: [false, true] } }, 'made bob@example.org a server admin'],
    ['user.role_changed down', { action: 'user.role_changed', object_key: B, domain_name: null, changes: { is_server_admin: [true, false] } }, 'removed the server admin role from bob@example.org'],
    ['user.email_changed', { action: 'user.email_changed', object_key: B, domain_name: null, changes: { email: ['old@example.org', B] } }, 'changed the email of old@example.org to bob@example.org'],
    ['user.deactivated', { action: 'user.deactivated', object_key: B, domain_name: null, changes: null }, 'deactivated user bob@example.org'],
    ['user.reactivated', { action: 'user.reactivated', object_key: B, domain_name: null, changes: null }, 'reactivated user bob@example.org'],
    ['user.deleted', { action: 'user.deleted', object_key: B, domain_name: null, changes: null }, 'deleted user bob@example.org'],
    ['user.two_factor_reset', { action: 'user.two_factor_reset', object_key: B, domain_name: null, changes: null }, 'reset two-factor authentication for bob@example.org'],
    ['domain.limits_changed', { action: 'domain.limits_changed', object_key: D, domain_name: D, changes: { max_mailboxes: [null, 20] } }, 'changed the limits of example.org'],
    ['domain.self_service_changed', { action: 'domain.self_service_changed', object_key: D, domain_name: D, changes: { aliases: [false, true] } }, 'changed what mailbox owners can change on example.org'],
];

/** The phase 4 DKIM actions: object the domain, the selector in `changes`. */
const DKIM: [string, { action: string; object_key: string; domain_name: string | null; changes: Record<string, unknown> | null }, string][] = [
    ['dkim.generated', { action: 'dkim.generated', object_key: D, domain_name: D, changes: null }, 'created a new DKIM key for example.org'],
    ['dkim.activated', { action: 'dkim.activated', object_key: D, domain_name: D, changes: { selector: 'es20261011', forced: false } }, 'switched example.org to the DKIM key es20261011'],
    [
        'dkim.activated, forced',
        { action: 'dkim.activated', object_key: D, domain_name: D, changes: { selector: 'es20261011', forced: true } },
        'switched example.org to the DKIM key es20261011 before DNS showed it',
    ],
    ['dkim.retired', { action: 'dkim.retired', object_key: D, domain_name: D, changes: { selector: 'es20250101' } }, 'retired the DKIM key es20250101 of example.org'],
];

/** Catch-all forwarding: the object is the source `@domain`; the sentence names the domain. */
const CATCH_ALL: [string, { action: string; object_key: string; domain_name: string | null; changes: Record<string, unknown> | null }, string][] = [
    [
        'forwarding.catch_all_set',
        { action: 'forwarding.catch_all_set', object_key: '@example.org', domain_name: D, changes: { old: [], new: ['anna@example.org'] } },
        'set catch-all forwarding for example.org',
    ],
    [
        'forwarding.catch_all_removed',
        { action: 'forwarding.catch_all_removed', object_key: '@example.org', domain_name: D, changes: { removed: 1, old: ['anna@example.org'], new: [] } },
        'removed catch-all forwarding for example.org',
    ],
    [
        'forwarding.catch_all_set without a domain',
        { action: 'forwarding.catch_all_set', object_key: '@example.org', domain_name: null, changes: null },
        'set catch-all forwarding for example.org',
    ],
];

describe('activityText for catch-all forwarding', () => {
    it.each(CATCH_ALL)('describes %s', (_name, entry, expected) => {
        expect(activityText(entry)).toBe(expected);
        expect(activityText({ ...entry, actor_kind: 'you' })).toBe(expected);
    });
});

describe('activityText for DKIM keys', () => {
    it.each(DKIM)('describes %s', (_name, entry, expected) => {
        expect(activityText(entry)).toBe(expected);
        expect(activityText({ ...entry, actor_kind: 'you' })).toBe(expected);
    });

    it('falls back to the raw action when the selector is missing', () => {
        expect(activityText({ action: 'dkim.activated', object_key: D, domain_name: D, changes: null })).toBe('dkim.activated example.org');
        expect(activityText({ action: 'dkim.retired', object_key: D, domain_name: D, changes: { selector: 7 } })).toBe('dkim.retired example.org');
    });
});

describe('activityText for the mailbox switches', () => {
    const switches: [string, boolean, string][] = [
        ['mailbox.receiving_changed', false, 'stopped mail delivery to mailbox bob@example.org'],
        ['mailbox.receiving_changed', true, 'resumed mail delivery to mailbox bob@example.org'],
        ['mailbox.sendonly_changed', true, 'made mailbox bob@example.org send-only'],
        ['mailbox.sendonly_changed', false, 'made mailbox bob@example.org send and receive'],
    ];

    it.each(switches)('describes %s -> %s', (action, now, expected) => {
        const entry = { action, object_key: B, domain_name: D, changes: { old: !now, new: now } };
        expect(activityText(entry)).toBe(expected);
        expect(activityText({ ...entry, actor_kind: 'you' as const })).toBe(expected);
    });

    it('falls back to the raw action when the new value is missing', () => {
        expect(activityText({ action: 'mailbox.receiving_changed', object_key: B, domain_name: D, changes: null })).toBe('mailbox.receiving_changed bob@example.org');
    });
});

describe('activityText for sharing and users', () => {
    it.each(SHARING)('describes %s', (_name, entry, expected) => {
        expect(activityText(entry)).toBe(expected);
        expect(activityText({ ...entry, actor_kind: 'you' })).toBe(expected);
    });

    it('covers all fifteen new actions', () => {
        expect(new Set(SHARING.map(([, e]) => e.action)).size).toBe(15);
    });

    it('falls back to the raw action when a role it needs is missing or unknown', () => {
        expect(activityText({ action: 'grant.created', object_key: B, domain_name: D, changes: null })).toBe('grant.created bob@example.org');
        expect(activityText({ action: 'grant.changed', object_key: B, domain_name: D, changes: { role: ['owner', 'manager'] } })).toBe(
            'grant.changed bob@example.org',
        );
    });

    it('names the old address as the new one when the email change has no old value', () => {
        expect(activityText({ action: 'user.email_changed', object_key: B, domain_name: null, changes: null })).toBe(
            'changed the email of bob@example.org to bob@example.org',
        );
    });
});

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
        ['mailbox', 'anna@example.org', 'anna@example.org'],
    ] as const)('labels the %s actor', (actor_kind, actor_name, expected) => {
        expect(actorLabel({ actor_kind, actor_name })).toBe(expected);
    });
});
