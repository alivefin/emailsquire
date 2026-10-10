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

    it.each([
        ['user.password_changed', 'vaihtoi käyttäjän anna@example.org salasanan', 'vaihdoit käyttäjän anna@example.org salasanan'],
        ['user.password_reset', 'nollasi käyttäjän anna@example.org salasanan palautuslinkillä', 'nollasit käyttäjän anna@example.org salasanan palautuslinkillä'],
        ['user.two_factor_enabled', 'otti käyttäjän anna@example.org kaksivaiheisen tunnistautumisen käyttöön', 'otit käyttäjän anna@example.org kaksivaiheisen tunnistautumisen käyttöön'],
        ['user.two_factor_disabled', 'poisti käyttäjän anna@example.org kaksivaiheisen tunnistautumisen käytöstä', 'poistit käyttäjän anna@example.org kaksivaiheisen tunnistautumisen käytöstä'],
        ['user.recovery_codes_regenerated', 'loi uudet varakoodit käyttäjälle anna@example.org', 'loit uudet varakoodit käyttäjälle anna@example.org'],
        ['user.sessions_revoked', 'kirjasi käyttäjän anna@example.org ulos kaikkialta muualta', 'kirjasit käyttäjän anna@example.org ulos kaikkialta muualta'],
    ])('words %s in Finnish, for someone else and for you', async (action, other, you) => {
        await setLocale('fi');
        expect(activityText({ action, object_key: 'anna@example.org', actor_kind: 'user' })).toBe(other);
        expect(activityText({ action, object_key: 'anna@example.org', actor_kind: 'you' })).toBe(you);
    });

    it('shows an unknown action as it is', async () => {
        await setLocale('fi');
        expect(activityText({ action: 'x.y', object_key: 'z' })).toBe('x.y z');
    });
});
