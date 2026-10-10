import { describe, expect, it } from 'vitest';
import { setLocale } from '../../i18n';
import { activityText, actorLabel } from './activityText';

describe('activityText in Finnish', () => {
    it('words mailbox.create in Finnish and keeps the object as data', async () => {
        await setLocale('fi');
        expect(activityText({ action: 'mailbox.create', object_key: 'anna@example.org' })).toBe('loi postilaatikon anna@example.org');
    });

    it('agrees with "sinä" when you acted', async () => {
        await setLocale('fi');
        expect(activityText({ action: 'mailbox.create', object_key: 'anna@example.org', actor_kind: 'you' })).toBe('loit postilaatikon anna@example.org');
        expect(activityText({ action: 'mailbox.disable', object_key: 'anna@example.org', actor_kind: 'you' })).toBe('poistit postilaatikon anna@example.org käytöstä');
        expect(activityText({ action: 'mailbox.create', object_key: 'anna@example.org', actor_kind: 'user' })).toBe('loi postilaatikon anna@example.org');
    });

    it.each([
        ['you', 'You', 'Sinä'],
        ['user', 'Bob Other', 'Bob Other'],
        ['deleted_user', 'Deleted user', 'Poistettu käyttäjä'],
        ['cli', 'Command line', 'Komentorivi'],
        ['system', 'System', 'Järjestelmä'],
    ] as const)('labels the %s actor in Finnish', async (actor_kind, actor_name, expected) => {
        await setLocale('fi');
        expect(actorLabel({ actor_kind, actor_name })).toBe(expected);
    });

    it('shows an unknown action as it is', async () => {
        await setLocale('fi');
        expect(activityText({ action: 'x.y', object_key: 'z' })).toBe('x.y z');
    });
});
