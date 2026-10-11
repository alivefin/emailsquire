import i18next from 'i18next';

export type ActorKind = 'you' | 'user' | 'deleted_user' | 'cli' | 'system' | 'mailbox';

export interface ActivityEntry {
    action: string;
    object_key: string;
    actor_kind?: ActorKind;
    domain_name?: string | null;
    changes?: Record<string, unknown> | null;
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
    'user.password_changed': 'userPasswordChanged',
    'user.password_reset': 'userPasswordReset',
    'user.two_factor_enabled': 'userTwoFactorEnabled',
    'user.two_factor_disabled': 'userTwoFactorDisabled',
    'user.recovery_codes_regenerated': 'userRecoveryCodesRegenerated',
    'user.sessions_revoked': 'userSessionsRevoked',
    'portal.password_changed': 'portalPasswordChanged',
    'portal.forwarding_changed': 'portalForwardingChanged',
    'portal.alias_created': 'portalAliasCreated',
    'portal.alias_deleted': 'portalAliasDeleted',
    'domain.dns_checked': 'domainDnsChecked',
    'dkim.generated': 'dkimGenerated',
};

/**
 * Who acted, in the console's language: a user's name and a mailbox owner's address as they are, the
 * other kinds from the catalogue.
 */
export function actorLabel(e: ActorEntry): string {
    switch (e.actor_kind) {
        case 'user':
        case 'mailbox':
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

type Role = 'manager' | 'viewer' | 'server_admin';

function isRole(value: unknown): value is Role {
    return value === 'manager' || value === 'viewer' || value === 'server_admin';
}

/** The role word for one grammatical use (Finnish inflects it: "hallinnoijaksi", "katselijasta"). */
function roleWord(role: Role, use: 'name' | 'to' | 'from'): string {
    return i18next.t(`activityRole.${use}.${role}`, { ns: 'dashboard' });
}

/** `[old, new]` from a `changes` entry, or null when it isn't a pair. */
function pair(value: unknown): [unknown, unknown] | null {
    return Array.isArray(value) && value.length === 2 ? [value[0], value[1]] : null;
}

/**
 * The actions whose sentence needs more than `object`: the key, and the values read from `domain_name` and
 * `changes` (the role of an invitation or grant, the old email, the welcome email's recipient). Null when a
 * value the sentence needs is missing.
 */
function sharingSentence(e: ActivityEntry): { key: string; values: Record<string, string> } | null {
    const changes = e.changes ?? {};
    const role = changes.role;
    const domain = e.domain_name ?? null;
    switch (e.action) {
        case 'user.invited':
        case 'invitation.accepted': {
            const base = e.action === 'user.invited' ? 'userInvited' : 'invitationAccepted';
            if (role === 'server_admin') return { key: `${base}Server`, values: {} };
            if (!isRole(role) || domain === null) return null;
            return { key: base, values: { domain, role: roleWord(role, 'to') } };
        }
        case 'invitation.resent':
            return { key: 'invitationResent', values: {} };
        case 'invitation.revoked':
            return { key: 'invitationRevoked', values: {} };
        case 'grant.created':
            if (!isRole(role) || domain === null) return null;
            return { key: 'grantCreated', values: { domain, role: roleWord(role, 'to') } };
        case 'grant.changed': {
            const roles = pair(role);
            if (!roles || !isRole(roles[0]) || !isRole(roles[1]) || domain === null) return null;
            return { key: 'grantChanged', values: { domain, from: roleWord(roles[0], 'from'), to: roleWord(roles[1], 'to') } };
        }
        case 'grant.removed':
            if (!isRole(role) || domain === null) return null;
            return { key: 'grantRemoved', values: { domain, role: roleWord(role, 'name') } };
        case 'user.role_changed': {
            const admin = pair(changes.is_server_admin)?.[1];
            if (typeof admin !== 'boolean') return null;
            return { key: admin ? 'userRoleGranted' : 'userRoleRevoked', values: {} };
        }
        case 'user.email_changed': {
            const old = pair(changes.email)?.[0];
            return { key: 'userEmailChanged', values: { from: typeof old === 'string' ? old : e.object_key } };
        }
        case 'user.deactivated':
            return { key: 'userDeactivated', values: {} };
        case 'user.reactivated':
            return { key: 'userReactivated', values: {} };
        case 'user.deleted':
            return { key: 'userDeleted', values: {} };
        case 'user.two_factor_reset':
            return { key: 'userTwoFactorReset', values: {} };
        case 'domain.limits_changed':
            return { key: 'domainLimitsChanged', values: {} };
        case 'domain.self_service_changed':
            return { key: 'domainSelfServiceChanged', values: {} };
        case 'dkim.activated': {
            const selector = changes.selector;
            if (typeof selector !== 'string') return null;
            return { key: changes.forced === true ? 'dkimActivatedForced' : 'dkimActivated', values: { selector } };
        }
        case 'dkim.retired': {
            const selector = changes.selector;
            return typeof selector === 'string' ? { key: 'dkimRetired', values: { selector } } : null;
        }
        case 'mailbox.welcome_sent': {
            const to = changes.to;
            return typeof to === 'string' ? { key: 'mailboxWelcomeSentTo', values: { to } } : { key: 'mailboxWelcomeSent', values: {} };
        }
        default:
            return null;
    }
}

/**
 * The catalogue key under `dashboard:activityText` (without the `_you` context) and the values for `e`'s
 * sentence; null when the action has none, and the raw `action object` fallback applies.
 */
export function activitySentence(e: ActivityEntry): { key: string; values: Record<string, string> } | null {
    const sharing = sharingSentence(e);
    if (sharing) return sharing;
    const key = KEYS[e.action];
    return key === undefined ? null : { key, values: {} };
}

/**
 * A lowercase past-tense sentence; the page prefixes `actorLabel`. When the requester acted, the `_you`
 * form agrees with "you" (Finnish "loit", not "loi").
 */
export function activityText(e: ActivityEntry): string {
    const context = e.actor_kind === 'you' ? 'you' : undefined;
    const sentence = activitySentence(e);
    if (sentence === null) return i18next.t('activityText.unknown', { ns: 'dashboard', action: e.action, object: e.object_key });
    return i18next.t(`activityText.${sentence.key}`, { ns: 'dashboard', object: e.object_key, context, ...sentence.values });
}
