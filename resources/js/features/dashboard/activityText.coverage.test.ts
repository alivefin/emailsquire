import i18next from 'i18next';
import { describe, expect, it } from 'vitest';
import auditActions from '../../api/auditActions.json';
import { setLocale, type Locale } from '../../i18n';
import { activitySentence, activityText, type ActivityEntry } from './activityText';

/**
 * Every action the backend writes (`AuditActions::GROUPS`, exported to auditActions.json and kept equal
 * by tests/Unit/MailServer/AuditActionsExportTest.php) must read as a translated sentence, never as the
 * raw `action object` fallback.
 */
const ALL: string[] = Object.values(auditActions).flat();

const D = 'example.org';
const B = 'bob@example.org';

/** The `changes` (and domain) the backend writes for the actions whose sentence needs them. */
const SAMPLES: Record<string, Partial<ActivityEntry>> = {
    'user.invited': { changes: { role: 'manager' } },
    'invitation.accepted': { changes: { role: 'viewer' } },
    'invitation.resent': { changes: { role: 'viewer' } },
    'invitation.revoked': { changes: { role: 'viewer' } },
    'grant.created': { changes: { role: 'viewer' } },
    'grant.changed': { changes: { role: ['viewer', 'manager'] } },
    'grant.removed': { changes: { role: 'manager' } },
    'user.role_changed': { changes: { is_server_admin: [false, true] } },
    'user.email_changed': { changes: { email: ['old@example.org', B] } },
    'mailbox.welcome_sent': { changes: { to: 'anna.home@example.net' } },
};

function entry(action: string, actor_kind: 'user' | 'you'): ActivityEntry {
    return { action, object_key: B, domain_name: D, changes: null, actor_kind, ...SAMPLES[action] };
}

describe('activityText covers every audit action', () => {
    it('reads the backend list', () => {
        expect(ALL.length).toBeGreaterThan(30);
        expect(ALL).toContain('mailbox.welcome_sent');
        expect(new Set(ALL).size).toBe(ALL.length);
    });

    it.each(['en', 'fi'] as Locale[])('words every action in %s, for someone else and for you', async (locale) => {
        await setLocale(locale);
        const raw: string[] = [];
        for (const action of ALL) {
            for (const kind of ['user', 'you'] as const) {
                const e = entry(action, kind);
                const text = activityText(e);
                // A missing catalogue key comes back as the key itself ("activityText.mailboxCreate").
                if (text.includes(action) || text.startsWith('activityText.') || text.trim() === '' || text.includes('{{')) {
                    raw.push(`${action} (${kind}): ${text}`);
                }
                // Without its own `_you` form, "you" silently borrows the third person ("Sinä loi").
                const sentence = activitySentence(e);
                const key = `dashboard:activityText.${sentence?.key ?? '(none)'}${kind === 'you' ? '_you' : ''}`;
                if (!i18next.exists(key, { lng: locale, fallbackLng: false })) raw.push(`${action} (${kind}): no ${key} in ${locale}`);
            }
        }
        expect(raw).toEqual([]);
    });

    it('words the welcome email with its recipient', async () => {
        expect(activityText(entry('mailbox.welcome_sent', 'user'))).toBe('sent the setup instructions for bob@example.org to anna.home@example.net');
        expect(activityText({ action: 'mailbox.welcome_sent', object_key: B, changes: null })).toBe('sent the setup instructions for bob@example.org');
        await setLocale('fi');
        expect(activityText(entry('mailbox.welcome_sent', 'user'))).toBe(
            'lähetti postilaatikon bob@example.org käyttöönotto-ohjeet osoitteeseen anna.home@example.net',
        );
        expect(activityText(entry('mailbox.welcome_sent', 'you'))).toBe(
            'lähetit postilaatikon bob@example.org käyttöönotto-ohjeet osoitteeseen anna.home@example.net',
        );
    });
});
