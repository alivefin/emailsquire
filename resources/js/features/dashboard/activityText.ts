import i18next from 'i18next';

export interface ActivityEntry {
    action: string;
    object_key: string;
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

/** A lowercase past-tense sentence; the page prefixes the actor's name. */
export function activityText(e: ActivityEntry): string {
    const key = KEYS[e.action];
    if (key === undefined) return i18next.t('activityText.unknown', { ns: 'dashboard', action: e.action, object: e.object_key });
    return i18next.t(`activityText.${key}`, { ns: 'dashboard', object: e.object_key });
}
