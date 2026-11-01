<?php

namespace App\MailServer;

use App\Modules\ModuleRegistry;

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
            'mailbox.receiving_changed',
            'mailbox.sendonly_changed',
            'mailbox.password_reset',
            'mailbox.disable',
            'mailbox.enable',
            'mailbox.delete',
            'mailbox.welcome_sent',
            'maildir.restore',
            'maildir.retry',
            'portal.password_changed',
            'portal.forwarding_changed',
            'portal.alias_created',
            'portal.alias_deleted',
        ],
        'forwarding' => [
            'forwarding.replace',
            'forwarding.delete',
            'forwarding.catch_all_set',
            'forwarding.catch_all_removed',
        ],
        'domains' => [
            'domain.create',
            'domain.delete',
            'domain.limits_changed',
            'domain.self_service_changed',
            'domain.dns_checked',
            'dkim.generated',
            'dkim.activated',
            'dkim.retired',
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
            'user.passkey_added',
            'user.passkey_removed',
            'user.passkey_renamed',
            'admin.create',
            'admin.setup',
        ],
    ];

    /**
     * The core actions people with a limited DomainScope may read: every core action except `admin.*`,
     * `maildir.*` and `user.*` other than `user.invited`. An allowlist, so what a module records (loaded,
     * unloaded or undeclared) is never in it.
     *
     * @return list<string>
     */
    public static function visibleToDomainScopes(): array
    {
        return array_values(array_filter(
            array_merge(...array_values(self::GROUPS)),
            fn (string $action): bool => ! (str_starts_with($action, 'admin.')
                || str_starts_with($action, 'maildir.')
                || (str_starts_with($action, 'user.') && $action !== 'user.invited')),
        ));
    }

    /** @return list<string> the prefixes of the core's actions, up to and including the first dot */
    public static function corePrefixes(): array
    {
        return array_values(array_unique(array_map(
            fn (string $action): string => substr($action, 0, (int) strpos($action, '.') + 1),
            array_merge(...array_values(self::GROUPS)),
        )));
    }

    /**
     * The core's groups and the loaded modules' (a module's group never shares a name or an action with
     * the core's; ModuleLoader refuses one that does). GROUPS stays the core's alone, as the exported JSON.
     *
     * @return array<string, list<string>>
     */
    public static function groups(): array
    {
        return [...self::GROUPS, ...app(ModuleRegistry::class)->auditGroups()];
    }

    /** @return list<string> every action the app and its loaded modules write */
    public static function all(): array
    {
        return array_merge(...array_values(self::groups()));
    }

    /** @return list<string> the actions of $group; [] for a group that doesn't exist */
    public static function group(string $group): array
    {
        return self::groups()[$group] ?? [];
    }
}
