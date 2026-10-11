import { describe, expect, it } from 'vitest';
import { setLocale } from '../../i18n';
import { activityText, actorLabel } from './activityText';

const B = 'bob@example.org';
const D = 'example.org';

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
        ['mailbox', 'anna@example.org', 'anna@example.org'],
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
        ['portal.password_changed', 'vaihtoi postilaatikkonsa salasanan', 'vaihdoit postilaatikkosi salasanan'],
        ['portal.forwarding_changed', 'muutti postilaatikkonsa edelleenlähetystä', 'muutit postilaatikkosi edelleenlähetystä'],
    ])('words %s in Finnish, for someone else and for you', async (action, other, you) => {
        await setLocale('fi');
        expect(activityText({ action, object_key: 'anna@example.org', actor_kind: 'user' })).toBe(other);
        expect(activityText({ action, object_key: 'anna@example.org', actor_kind: 'you' })).toBe(you);
    });

    it.each([
        ['portal.alias_created', 'lisäsi postilaatikkoonsa aliaksen sales@example.org', 'lisäsit postilaatikkoosi aliaksen sales@example.org'],
        ['portal.alias_deleted', 'poisti postilaatikostaan aliaksen sales@example.org', 'poistit postilaatikostasi aliaksen sales@example.org'],
    ])('words %s in Finnish, with the alias as the object', async (action, other, you) => {
        await setLocale('fi');
        expect(activityText({ action, object_key: 'sales@example.org', actor_kind: 'mailbox' })).toBe(other);
        expect(activityText({ action, object_key: 'sales@example.org', actor_kind: 'you' })).toBe(you);
    });

    it.each([
        ['user.invited', { object_key: B, domain_name: D, changes: { role: 'manager' } }, 'kutsui osoitteen bob@example.org verkkotunnukselle example.org hallinnoijaksi', 'kutsuit osoitteen bob@example.org verkkotunnukselle example.org hallinnoijaksi'],
        ['user.invited', { object_key: B, domain_name: null, changes: { role: 'server_admin' } }, 'kutsui osoitteen bob@example.org palvelimen ylläpitäjäksi', 'kutsuit osoitteen bob@example.org palvelimen ylläpitäjäksi'],
        ['invitation.resent', { object_key: B, domain_name: D, changes: { role: 'viewer' } }, 'lähetti kutsun uudelleen osoitteeseen bob@example.org', 'lähetit kutsun uudelleen osoitteeseen bob@example.org'],
        ['invitation.revoked', { object_key: B, domain_name: D, changes: { role: 'viewer' } }, 'perui osoitteen bob@example.org kutsun', 'peruit osoitteen bob@example.org kutsun'],
        ['invitation.accepted', { object_key: B, domain_name: D, changes: { role: 'viewer' } }, 'hyväksyi kutsun verkkotunnukselle example.org katselijaksi', 'hyväksyit kutsun verkkotunnukselle example.org katselijaksi'],
        ['invitation.accepted', { object_key: B, domain_name: null, changes: { role: 'server_admin' } }, 'hyväksyi kutsun palvelimen ylläpitäjäksi', 'hyväksyit kutsun palvelimen ylläpitäjäksi'],
        ['grant.created', { object_key: B, domain_name: D, changes: { role: 'viewer' } }, 'lisäsi käyttäjän bob@example.org verkkotunnukselle example.org katselijaksi', 'lisäsit käyttäjän bob@example.org verkkotunnukselle example.org katselijaksi'],
        ['grant.changed', { object_key: B, domain_name: D, changes: { role: ['viewer', 'manager'] } }, 'vaihtoi käyttäjän bob@example.org roolin verkkotunnuksella example.org katselijasta hallinnoijaksi', 'vaihdoit käyttäjän bob@example.org roolin verkkotunnuksella example.org katselijasta hallinnoijaksi'],
        ['grant.removed', { object_key: B, domain_name: D, changes: { role: 'manager' } }, 'poisti käyttäjän bob@example.org verkkotunnukselta example.org (hallinnoija)', 'poistit käyttäjän bob@example.org verkkotunnukselta example.org (hallinnoija)'],
        ['user.role_changed', { object_key: B, domain_name: null, changes: { is_server_admin: [false, true] } }, 'teki käyttäjästä bob@example.org palvelimen ylläpitäjän', 'teit käyttäjästä bob@example.org palvelimen ylläpitäjän'],
        ['user.role_changed', { object_key: B, domain_name: null, changes: { is_server_admin: [true, false] } }, 'poisti käyttäjältä bob@example.org palvelimen ylläpitäjän roolin', 'poistit käyttäjältä bob@example.org palvelimen ylläpitäjän roolin'],
        ['user.email_changed', { object_key: B, domain_name: null, changes: { email: ['old@example.org', B] } }, 'vaihtoi käyttäjän old@example.org sähköpostiosoitteeksi bob@example.org', 'vaihdoit käyttäjän old@example.org sähköpostiosoitteeksi bob@example.org'],
        ['user.deactivated', { object_key: B, domain_name: null, changes: null }, 'poisti käyttäjän bob@example.org käytöstä', 'poistit käyttäjän bob@example.org käytöstä'],
        ['user.reactivated', { object_key: B, domain_name: null, changes: null }, 'otti käyttäjän bob@example.org uudelleen käyttöön', 'otit käyttäjän bob@example.org uudelleen käyttöön'],
        ['user.deleted', { object_key: B, domain_name: null, changes: null }, 'poisti käyttäjän bob@example.org', 'poistit käyttäjän bob@example.org'],
        ['user.two_factor_reset', { object_key: B, domain_name: null, changes: null }, 'nollasi käyttäjän bob@example.org kaksivaiheisen tunnistautumisen', 'nollasit käyttäjän bob@example.org kaksivaiheisen tunnistautumisen'],
        ['domain.limits_changed', { object_key: D, domain_name: D, changes: { max_mailboxes: [null, 20] } }, 'muutti verkkotunnuksen example.org rajoja', 'muutit verkkotunnuksen example.org rajoja'],
        ['domain.self_service_changed', { object_key: D, domain_name: D, changes: { aliases: [false, true] } }, 'muutti verkkotunnuksen example.org postilaatikon omistajien oikeuksia', 'muutit verkkotunnuksen example.org postilaatikon omistajien oikeuksia'],
        ['domain.dns_checked', { object_key: D, domain_name: D, changes: null }, 'tarkisti verkkotunnuksen example.org DNS-tietueet', 'tarkistit verkkotunnuksen example.org DNS-tietueet'],
        ['dkim.generated', { object_key: D, domain_name: D, changes: null }, 'loi verkkotunnukselle example.org uuden DKIM-avaimen', 'loit verkkotunnukselle example.org uuden DKIM-avaimen'],
        ['dkim.activated', { object_key: D, domain_name: D, changes: { selector: 'es20261011', forced: false } }, 'otti verkkotunnuksen example.org DKIM-avaimen es20261011 käyttöön', 'otit verkkotunnuksen example.org DKIM-avaimen es20261011 käyttöön'],
        [
            'dkim.activated',
            { object_key: D, domain_name: D, changes: { selector: 'es20261011', forced: true } },
            'otti verkkotunnuksen example.org DKIM-avaimen es20261011 käyttöön ennen kuin DNS näytti sen',
            'otit verkkotunnuksen example.org DKIM-avaimen es20261011 käyttöön ennen kuin DNS näytti sen',
        ],
        ['dkim.retired', { object_key: D, domain_name: D, changes: { selector: 'es20250101' } }, 'poisti verkkotunnuksen example.org DKIM-avaimen es20250101', 'poistit verkkotunnuksen example.org DKIM-avaimen es20250101'],
    ] as const)('words %s in Finnish, for someone else and for you', async (action, rest, other, you) => {
        await setLocale('fi');
        const entry = { action, ...rest, changes: rest.changes as Record<string, unknown> | null };
        expect(activityText({ ...entry, actor_kind: 'user' })).toBe(other);
        expect(activityText({ ...entry, actor_kind: 'you' })).toBe(you);
    });

    it('shows an unknown action as it is', async () => {
        await setLocale('fi');
        expect(activityText({ action: 'x.y', object_key: 'z' })).toBe('x.y z');
    });
});
