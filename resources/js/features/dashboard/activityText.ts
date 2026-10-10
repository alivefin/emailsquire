export interface ActivityEntry {
    action: string;
    object_key: string;
}

const sentences: Record<string, (key: string) => string> = {
    'domain.create': (k) => `added domain ${k}`,
    'domain.delete': (k) => `deleted domain ${k}`,
    'mailbox.create': (k) => `created mailbox ${k}`,
    'mailbox.update': (k) => `changed mailbox ${k}`,
    'mailbox.password_reset': (k) => `reset the password of ${k}`,
    'mailbox.disable': (k) => `disabled mailbox ${k}`,
    'mailbox.enable': (k) => `enabled mailbox ${k}`,
    'mailbox.delete': (k) => `deleted mailbox ${k}`,
    'forwarding.replace': (k) => `set forwarding for ${k}`,
    'forwarding.delete': (k) => `removed forwarding for ${k}`,
    'blocked.create': (k) => `blocked ${k}`,
    'blocked.delete': (k) => `unblocked ${k}`,
    'admin.create': (k) => `issued a setup link for ${k}`,
    'admin.setup': (k) => `set the password for ${k}`,
    'maildir.restore': (k) => `queued a mail restore for ${k}`,
    'maildir.retry': (k) => `retried a maildir task (#${k})`,
};

/** A lowercase past-tense sentence; the page prefixes the actor's name. */
export function activityText(e: ActivityEntry): string {
    const make = sentences[e.action];
    return make ? make(e.object_key) : `${e.action} ${e.object_key}`;
}
