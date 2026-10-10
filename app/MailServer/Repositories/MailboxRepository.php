<?php

namespace App\MailServer\Repositories;

use App\MailServer\Address\DomainName;
use App\MailServer\Address\EmailAddress;
use App\MailServer\AddressClaims;
use App\MailServer\AuditLog;
use App\MailServer\HelperTasks;
use App\MailServer\MailboxSessions;
use App\MailServer\Models\Alias;
use App\MailServer\Models\Domain;
use App\MailServer\Models\Mailbox;
use App\MailServer\PasswordGenerator;
use App\MailServer\QuotaFormat;
use App\MailServer\SourceLock;
use App\Models\DisabledMailbox;
use App\Models\MailboxSetting;
use App\Models\MailboxUsage;
use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MailboxRepository
{
    private const SCHEME = '{BLF-CRYPT}';

    public function __construct(
        private readonly AuditLog $audit,
        private readonly AddressClaims $claims,
        private readonly MailboxSessions $sessions,
        private readonly HelperTasks $tasks,
    ) {}

    /** The stored form Dovecot 2.4 accepts: scheme prefix plus a cost-12 bcrypt hash. */
    public function hash(#[\SensitiveParameter] string $pw): string
    {
        return self::SCHEME.password_hash($pw, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /** Hashes before taking the lock: bcrypt at cost 12 takes a few hundred milliseconds. */
    public function create(Domain $d, EmailAddress $a, #[\SensitiveParameter] string $password, int $quotaBytes, ?string $displayName): Mailbox
    {
        $address = $a->address();
        $hash = $this->hash($password);

        // The lock wraps the transaction so it is released only after the new row is committed.
        return SourceLock::run($address, fn () => DB::transaction(function () use ($d, $a, $address, $hash, $quotaBytes, $displayName) {
            $this->shareLockDomain($d);
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

    /**
     * One transaction and one audit entry for whatever the PATCH really changed; nothing at all when
     * nothing changes. Runs under the address lock on the re-read row, like the other mailbox writes.
     */
    public function update(Mailbox $m, ?int $quotaBytes, bool $setDisplayName, ?string $displayName): void
    {
        if ($quotaBytes === null && ! $setDisplayName) {
            return;
        }

        SourceLock::run($m->email, fn () => DB::transaction(function () use ($m, $quotaBytes, $setDisplayName, $displayName) {
            $live = $this->lockedMailbox($m);
            $changes = [];

            $stored = is_numeric($live->getAttribute('quota')) ? (int) $live->getAttribute('quota') : 0;
            if ($quotaBytes !== null && $stored !== $quotaBytes) {
                $live->forceFill(['quota' => $quotaBytes])->save();
                $changes['quota_bytes'] = ['old' => QuotaFormat::effectiveBytes($stored), 'new' => $quotaBytes];
            }

            if ($setDisplayName) {
                $setting = $this->settings($live->email, $this->domainId($live));
                if ($setting->display_name !== $displayName) {
                    $changes['display_name'] = ['old' => $setting->display_name, 'new' => $displayName];
                    $setting->forceFill(['display_name' => $displayName])->save();
                }
            }

            if ($changes !== []) {
                $this->audit->record('mailbox.update', 'mailbox', $live->email, $this->domainName($live), $changes);
            }
        }));
    }

    /**
     * Returns the new plaintext, which the caller shows once and nobody stores.
     * Runs under the address lock and re-reads the row, so it serialises with disable and enable.
     */
    public function resetPassword(Mailbox $m): string
    {
        $password = PasswordGenerator::generate();
        $hash = $this->hash($password);

        SourceLock::run($m->email, fn () => DB::transaction(function () use ($m, $hash) {
            $live = $this->lockedMailbox($m);

            // A disabled mailbox has a dead hash in virtual_users; the real one waits in the app table.
            $disabled = DisabledMailbox::query()->where('mailbox_email', $live->email)->lockForUpdate()->first();
            if ($disabled !== null) {
                $disabled->forceFill(['saved_password' => $hash])->save();
            } else {
                $live->forceFill(['password' => $hash])->save();
            }

            $this->audit->record('mailbox.password_reset', 'mailbox', $live->email, $this->domainName($live));
        }));

        $this->sessions->kick($m->email);

        return $password;
    }

    /** Swaps the live hash for an unusable one; mail keeps arriving, every login fails. Idempotent. */
    public function disable(Mailbox $m): void
    {
        $throwaway = $this->throwawayHash();

        $changed = SourceLock::run($m->email, fn () => DB::transaction(function () use ($m, $throwaway) {
            $live = $this->lockedMailbox($m);

            return $this->disableLocked($live, $throwaway);
        }));

        if ($changed) {
            $this->sessions->kick($m->email);
        }
    }

    /** Puts the saved hash back and forgets the disabled row. Idempotent. */
    public function enable(Mailbox $m): void
    {
        SourceLock::run($m->email, fn () => DB::transaction(function () use ($m) {
            $this->enableLocked($this->lockedMailbox($m));
        }));
    }

    /**
     * Removes the mailbox and every forwarding row from or to it, and queues its maildir for archiving.
     *
     * @return array{forwarding_removed: int}
     */
    public function delete(Mailbox $m): array
    {
        $result = SourceLock::run($m->email, fn () => DB::transaction(function () use ($m) {
            $live = $this->lockedMailbox($m);
            $address = $live->email;
            $lower = strtolower($address);

            // Legacy rows may be mixed case; compare lowercased on both sides, binary, so collation can't hide or add matches.
            $pointing = Alias::query()
                ->whereRaw('convert(lower(destination) using utf8mb4) collate utf8mb4_bin = ?', [$lower])
                ->whereRaw('convert(lower(source) using utf8mb4) collate utf8mb4_bin <> ?', [$lower]);
            $forwarding = (clone $pointing)->distinct()->count('source');

            Alias::query()->whereRaw('convert(lower(source) using utf8mb4) collate utf8mb4_bin = ?', [$lower])->delete();
            $pointing->delete();

            Mailbox::query()->whereKey($live->getKey())->delete();
            MailboxSetting::query()->where('mailbox_email', $address)->delete();
            DisabledMailbox::query()->where('mailbox_email', $address)->delete();
            MailboxUsage::query()->where('email', $address)->delete();

            // The maildir belongs to the stored spelling: Dovecot's home is /var/vmail/%{user | domain}/%{user | username}
            // and the userdb returns the stored email as the user, so Anna@Example.org lives in Example.org/Anna.
            $at = strrpos($address, '@');
            $local = $at === false ? $address : substr($address, 0, $at);
            $host = $at === false ? '' : substr($address, $at + 1);
            $this->tasks->queueArchive(new EmailAddress($local, new DomainName($host, $host)));

            $this->audit->record('mailbox.delete', 'mailbox', $address, $this->domainName($live), ['forwarding_removed' => $forwarding]);

            return ['address' => $address, 'forwarding_removed' => $forwarding];
        }));

        $this->sessions->kick($result['address']);

        return ['forwarding_removed' => $result['forwarding_removed']];
    }

    /**
     * Applies one action to up to 200 mailboxes of a domain in one transaction, one audit row each.
     * Mailboxes already in the target state are skipped. An unknown local fails the whole request.
     *
     * @param  list<string>  $locals
     * @param  'quota'|'disable'|'enable'|string  $action
     */
    public function bulk(Domain $d, array $locals, string $action, ?int $quotaBytes): int
    {
        $wanted = array_values(array_unique(array_map('strtolower', $locals)));
        sort($wanted);
        $addresses = array_map(fn (string $l) => $l.'@'.strtolower($d->name), $wanted);
        $throwaway = $action === 'disable' ? $this->throwawayHash() : '';

        $result = $this->withLocks($addresses, fn () => DB::transaction(function () use ($d, $wanted, $addresses, $action, $quotaBytes, $throwaway) {
            $this->shareLockDomain($d);
            $found = [];
            foreach (Mailbox::query()->where('domain_id', $d->id)->whereIn('email', $addresses)->lockForUpdate()->get() as $mailbox) {
                // The guide collation folds case and accents; keep only exact matches of the lowercased address.
                $found[strtolower($mailbox->email)] = $mailbox;
            }

            $unknown = array_values(array_filter($wanted, fn (string $l) => ! isset($found[$l.'@'.strtolower($d->name)])));
            if ($unknown !== []) {
                throw ValidationException::withMessages(['locals' => ['Unknown mailbox: '.implode(', ', $unknown).'.']]);
            }

            $changed = 0;
            $kick = [];
            foreach ($found as $mailbox) {
                $did = match ($action) {
                    'quota' => $this->bulkQuota($mailbox, $quotaBytes ?? throw new \LogicException('quota_bytes missing.')),
                    'disable' => $this->disableLocked($mailbox, $throwaway),
                    'enable' => $this->enableLocked($mailbox),
                    default => throw new \InvalidArgumentException("Unknown bulk action {$action}."),
                };
                if ($did) {
                    $changed++;
                    if ($action === 'disable') {
                        $kick[] = $mailbox->email;
                    }
                }
            }

            return ['changed' => $changed, 'kick' => $kick];
        }));

        if ($result['kick'] !== []) {
            $this->sessions->kick(...$result['kick']);
        }

        return $result['changed'];
    }

    private function bulkQuota(Mailbox $m, int $quotaBytes): bool
    {
        $stored = is_numeric($m->getAttribute('quota')) ? (int) $m->getAttribute('quota') : 0;
        if ($stored === $quotaBytes) {
            return false;
        }

        $old = QuotaFormat::effectiveBytes($stored);
        $m->forceFill(['quota' => $quotaBytes])->save();
        $this->audit->record('mailbox.update', 'mailbox', $m->email, $this->domainName($m), [
            'quota_bytes' => ['old' => $old, 'new' => $quotaBytes],
        ]);

        return true;
    }

    /** Caller holds the lock and a transaction, and passes the freshly locked row. */
    private function disableLocked(Mailbox $live, string $throwaway): bool
    {
        if (DisabledMailbox::query()->where('mailbox_email', $live->email)->lockForUpdate()->exists()) {
            return false;
        }

        $saved = $live->getAttribute('password');
        $disabled = new DisabledMailbox;
        $disabled->forceFill([
            'mailbox_email' => $live->email,
            'domain_id' => $this->domainId($live),
            'saved_password' => is_string($saved) ? $saved : '',
            'disabled_by' => Auth::id(),
            'disabled_at' => now(),
        ])->save();
        $live->forceFill(['password' => $throwaway])->save();

        $this->audit->record('mailbox.disable', 'mailbox', $live->email, $this->domainName($live));

        return true;
    }

    private function enableLocked(Mailbox $live): bool
    {
        $disabled = DisabledMailbox::query()->where('mailbox_email', $live->email)->lockForUpdate()->first();
        if ($disabled === null) {
            return false;
        }

        $live->forceFill(['password' => $disabled->saved_password])->save();
        DisabledMailbox::query()->where('mailbox_email', $live->email)->delete();

        $this->audit->record('mailbox.enable', 'mailbox', $live->email, $this->domainName($live));

        return true;
    }

    /**
     * First lock of every write that adds rows under a domain (lock order: domain, mailboxes, aliases).
     * A domain delete holds the row FOR UPDATE, so this waits for it and then 404s instead of orphaning rows.
     */
    private function shareLockDomain(Domain $d): void
    {
        Domain::query()->whereKey($d->getKey())->sharedLock()->firstOrFail();
    }

    /** The row as it is now, locked. A concurrent delete shows up as a missing row, so it is a 404. */
    private function lockedMailbox(Mailbox $m): Mailbox
    {
        return Mailbox::query()->whereKey($m->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * A hash of 32 random bytes nobody sees, so no password can match it. Hex-encoded because
     * bcrypt refuses NUL bytes (64 characters stay under its 72-byte limit).
     */
    private function throwawayHash(): string
    {
        return $this->hash(bin2hex(random_bytes(32)));
    }

    /**
     * Holds the source lock of every address, taken in sorted order so two bulk calls cannot deadlock.
     *
     * @template T
     *
     * @param  list<string>  $addresses
     * @param  Closure(): T  $fn
     * @return T
     */
    private function withLocks(array $addresses, Closure $fn): mixed
    {
        $addresses = array_values(array_unique($addresses));
        sort($addresses);

        foreach (array_reverse($addresses) as $address) {
            $inner = $fn;
            $fn = fn () => SourceLock::run($address, $inner);
        }

        return $fn();
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
