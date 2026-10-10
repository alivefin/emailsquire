<?php

namespace App\MailServer\Repositories;

use App\MailServer\Address\EmailAddress;
use App\MailServer\AddressClaims;
use App\MailServer\AuditLog;
use App\MailServer\MailboxSessions;
use App\MailServer\Models\Domain;
use App\MailServer\Models\Mailbox;
use App\MailServer\PasswordGenerator;
use App\MailServer\QuotaFormat;
use App\MailServer\SourceLock;
use App\Models\DisabledMailbox;
use App\Models\MailboxSetting;
use Illuminate\Support\Facades\DB;

class MailboxRepository
{
    private const SCHEME = '{BLF-CRYPT}';

    public function __construct(
        private readonly AuditLog $audit,
        private readonly AddressClaims $claims,
        private readonly MailboxSessions $sessions,
    ) {}

    /** The stored form Dovecot 2.4 accepts: scheme prefix plus a cost-12 bcrypt hash. */
    public function hash(string $pw): string
    {
        return self::SCHEME.password_hash($pw, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /** Hashes before taking the lock: bcrypt at cost 12 takes a few hundred milliseconds. */
    public function create(Domain $d, EmailAddress $a, string $password, int $quotaBytes, ?string $displayName): Mailbox
    {
        $address = $a->address();
        $hash = $this->hash($password);

        // The lock wraps the transaction so it is released only after the new row is committed.
        return SourceLock::run($address, fn () => DB::transaction(function () use ($d, $a, $address, $hash, $quotaBytes, $displayName) {
            $this->claims->assertCanCreateMailbox($a);

            $mailbox = new Mailbox;
            $mailbox->forceFill([
                'domain_id' => $d->id, 'email' => $address, 'password' => $hash, 'quota' => $quotaBytes,
            ])->save();

            if ($displayName !== null) {
                $this->settings($address, $d->id)->forceFill(['display_name' => $displayName])->save();
            }

            $this->audit->record('mailbox.create', 'mailbox', $address, $d->name, [
                'quota_bytes' => $quotaBytes, 'display_name' => $displayName,
            ]);

            return $mailbox;
        }));
    }

    public function setQuota(Mailbox $m, int $quotaBytes): void
    {
        $this->update($m, $quotaBytes, false, null);
    }

    public function setDisplayName(Mailbox $m, ?string $name): void
    {
        $this->update($m, null, true, $name);
    }

    /** One transaction and one audit entry for whatever the PATCH changed. */
    public function update(Mailbox $m, ?int $quotaBytes, bool $setDisplayName, ?string $displayName): void
    {
        DB::transaction(function () use ($m, $quotaBytes, $setDisplayName, $displayName) {
            $changes = [];

            if ($quotaBytes !== null) {
                $old = QuotaFormat::effectiveBytes(is_numeric($m->getAttribute('quota')) ? (int) $m->getAttribute('quota') : 0);
                $m->forceFill(['quota' => $quotaBytes])->save();
                $changes['quota_bytes'] = ['old' => $old, 'new' => $quotaBytes];
            }

            if ($setDisplayName) {
                $setting = $this->settings($m->email, $this->domainId($m));
                $changes['display_name'] = ['old' => $setting->display_name, 'new' => $displayName];
                $setting->forceFill(['display_name' => $displayName])->save();
            }

            $this->audit->record('mailbox.update', 'mailbox', $m->email, $this->domainName($m), $changes);
        });
    }

    /** Returns the new plaintext, which the caller shows once and nobody stores. */
    public function resetPassword(Mailbox $m): string
    {
        $password = PasswordGenerator::generate();
        $hash = $this->hash($password);

        DB::transaction(function () use ($m, $hash) {
            // A disabled mailbox has a dead hash in virtual_users; the real one waits in the app table.
            $disabled = DisabledMailbox::query()->where('mailbox_email', $m->email)->lockForUpdate()->first();
            if ($disabled !== null) {
                $disabled->forceFill(['saved_password' => $hash])->save();
            } else {
                $m->forceFill(['password' => $hash])->save();
            }

            $this->audit->record('mailbox.password_reset', 'mailbox', $m->email, $this->domainName($m));
        });

        $this->sessions->kick($m->email);

        return $password;
    }

    private function settings(string $address, int $domainId): MailboxSetting
    {
        return MailboxSetting::query()->firstOrNew(['mailbox_email' => $address], ['domain_id' => $domainId]);
    }

    private function domainId(Mailbox $m): int
    {
        $id = $m->getAttribute('domain_id');

        return is_numeric($id) ? (int) $id : 0;
    }

    private function domainName(Mailbox $m): ?string
    {
        $at = strrpos($m->email, '@');

        return $at === false ? null : strtolower(substr($m->email, $at + 1));
    }
}
