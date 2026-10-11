<?php

namespace App\MailServer;

/**
 * Every audit action the app writes (AuditLog::record()), grouped for the activity log's
 * `action_group` filter. Each action is in exactly one group; AuditLogTest fails when the code writes
 * an action that is missing here.
 */
final class AuditActions
{
    /** @var array<string, list<string>> group => action names */
    public const GROUPS = [
        'mailboxes' => [
            'mailbox.create',
            'mailbox.update',
            'mailbox.password_reset',
            'mailbox.disable',
            'mailbox.enable',
            'mailbox.delete',
            'mailbox.welcome_sent',
            'maildir.restore',
            'maildir.retry',
            'portal.password_changed',
        ],
        'forwarding' => [
            'forwarding.replace',
            'forwarding.delete',
        ],
        'domains' => [
            'domain.create',
            'domain.delete',
            'domain.limits_changed',
            'domain.self_service_changed',
        ],
        'blocked' => [
            'blocked.create',
            'blocked.delete',
        ],
        'access' => [
            'grant.created',
            'grant.changed',
            'grant.removed',
            'invitation.resent',
            'invitation.revoked',
            'invitation.accepted',
            'user.invited',
        ],
        'account' => [
            'user.email_changed',
            'user.role_changed',
            'user.deactivated',
            'user.reactivated',
            'user.deleted',
            'user.password_changed',
            'user.password_reset',
            'user.sessions_revoked',
            'user.two_factor_enabled',
            'user.two_factor_disabled',
            'user.two_factor_reset',
            'user.recovery_codes_regenerated',
            'admin.create',
            'admin.setup',
        ],
    ];

    /** @return list<string> every action the app writes */
    public static function all(): array
    {
        return array_merge(...array_values(self::GROUPS));
    }

    /** @return list<string> the actions of $group; [] for a group that doesn't exist */
    public static function group(string $group): array
    {
        return self::GROUPS[$group] ?? [];
    }
}
