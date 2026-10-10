import i18next from 'i18next';

export type ActorKind = 'you' | 'user' | 'deleted_user' | 'cli' | 'system';

export interface ActivityEntry {
    action: string;
    object_key: string;
    actor_kind?: ActorKind;
}

export interface ActorEntry {
    actor_kind: ActorKind;
    actor_name: string;
}

/** Audit action name -> catalogue key under `dashboard:activityText`. */
const KEYS: Record<string, string> = {
    'domain.create': 'domainCreate',
    'domain.delete': 'domainDelete',
    'mailbox.create': 'mailboxCreate',
    'mailbox.update': 'mailboxUpdate',
    'mailbox.password_reset': 'mailboxPasswordReset',
    'mailbox.disable': 'mailboxDisable',
    'mailbox.enable': 'mailboxEnable',
    'mailbox.delete': 'mailboxDelete',
    'forwarding.replace': 'forwardingReplace',
    'forwarding.delete': 'forwardingDelete',
    'blocked.create': 'blockedCreate',
    'blocked.delete': 'blockedDelete',
    'admin.create': 'adminCreate',
    'admin.setup': 'adminSetup',
    'maildir.restore': 'maildirRestore',
    'maildir.retry': 'maildirRetry',
};

/** Who acted, in the console's language: a user's name as it is, the other kinds from the catalogue. */
export function actorLabel(e: ActorEntry): string {
    switch (e.actor_kind) {
        case 'user':
            return e.actor_name;
        case 'you':
            return i18next.t('activity.actor.you', { ns: 'dashboard' });
        case 'cli':
            return i18next.t('activity.actor.cli', { ns: 'dashboard' });
        case 'system':
            return i18next.t('activity.actor.system', { ns: 'dashboard' });
        case 'deleted_user':
            return i18next.t('activity.actor.deletedUser', { ns: 'dashboard' });
    }
}

/**
 * A lowercase past-tense sentence; the page prefixes `actorLabel`. When the requester acted, the `_you`
 * form agrees with "you" (Finnish "loit", not "loi").
 */
export function activityText(e: ActivityEntry): string {
    const key = KEYS[e.action];
    if (key === undefined) return i18next.t('activityText.unknown', { ns: 'dashboard', action: e.action, object: e.object_key });
    const context = e.actor_kind === 'you' ? 'you' : undefined;
    return i18next.t(`activityText.${key}`, { ns: 'dashboard', object: e.object_key, context });
}
