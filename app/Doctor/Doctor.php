<?php

namespace App\Doctor;

use Illuminate\Contracts\Container\Container;
use ReflectionClass;
use Throwable;

final class Doctor
{
    /** Check classes in display order. */
    public const CHECKS = [
        Checks\DatabaseConnection::class,
        Checks\GuideSchemaShape::class,
        Checks\DomainUniqueKey::class,
        Checks\PasswordSchemes::class,
        Checks\AddressFormat::class,
        Checks\DomainMismatch::class,
        Checks\AliasShadowsMailbox::class,
        Checks\DuplicateAliases::class,
        Checks\AppMigrated::class,
        Checks\AppOrphans::class,
        Checks\MaildirQueue::class,
        Checks\PostfixAliasMaps::class,
        Checks\PostfixSenderLoginMaps::class,
        Checks\DoveadmApi::class,
        Checks\DovecotAuthSocket::class,
        Checks\MailRoot::class,
        Checks\DkimDir::class,
        Checks\RuntimeDebug::class,
        Checks\RuntimeCaches::class,
        Checks\FpmIni::class,
        Checks\MailQueueWorker::class,
        Checks\MailSmtp::class,
        Checks\MailHost::class,
        Checks\RequireTwoFactorSetting::class,
    ];

    public function __construct(private readonly Container $container) {}

    /**
     * Runs every check and returns one result per check, in CHECKS order.
     * A check that throws (even while being resolved) becomes a Fail.
     *
     * @return list<CheckResult>
     */
    public function run(bool $requireServer = false): array
    {
        $results = [];

        foreach (self::CHECKS as $class) {
            $check = null;

            try {
                $check = $this->container->make($class);
                $result = $check->run();
            } catch (Throwable $e) {
                $result = new CheckResult(
                    $this->safeId($check ?? $class),
                    Status::Fail,
                    $this->safeTitle($check ?? $class),
                    $e::class.': '.$e->getMessage(),
                );
            }

            if ($requireServer && $result->status === Status::Skip && $check?->isServerCheck() === true) {
                $result = new CheckResult(
                    $result->id,
                    Status::Fail,
                    $result->title,
                    'Required: '.($result->detail ?? 'skipped'),
                    $result->items,
                );
            }

            $results[] = $result;
        }

        return $results;
    }

    /**
     * The check's id, or its short class name when the check can't give one.
     *
     * @param  Check|class-string<Check>  $subject
     */
    private function safeId(Check|string $subject): string
    {
        try {
            return $subject instanceof Check ? $subject->id() : $this->shortName($subject);
        } catch (Throwable) {
            return $this->shortName($subject);
        }
    }

    /** @param  Check|class-string<Check>  $subject */
    private function safeTitle(Check|string $subject): string
    {
        try {
            return $subject instanceof Check ? $subject->title() : $this->shortName($subject);
        } catch (Throwable) {
            return $this->shortName($subject);
        }
    }

    /** @param  Check|class-string<Check>  $subject */
    private function shortName(Check|string $subject): string
    {
        return (new ReflectionClass($subject))->getShortName();
    }
}
