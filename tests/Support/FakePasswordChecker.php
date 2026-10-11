<?php

namespace Tests\Support;

use App\MailServer\Dovecot\DovecotAuthException;
use App\MailServer\Dovecot\PasswordChecker;

/**
 * The Pest suite's Dovecot: accepts only the user and password pairs a test gave it, and records
 * who was checked from which IP (never the password). Bound for every Feature test (tests/Pest.php).
 */
final class FakePasswordChecker implements PasswordChecker
{
    /** @var array<string, string> user => password */
    private array $accepted = [];

    /** @var list<array{user: string, ip: string|null}> */
    public array $calls = [];

    private ?DovecotAuthException $failure = null;

    public function accept(string $user, string $password): self
    {
        $this->accepted[$user] = $password;

        return $this;
    }

    /** Every later check throws $e, as an unreachable or broken auth socket does. */
    public function failWith(DovecotAuthException $e): self
    {
        $this->failure = $e;

        return $this;
    }

    public function verify(string $user, #[\SensitiveParameter] string $password, ?string $remoteIp = null): bool
    {
        $this->calls[] = ['user' => $user, 'ip' => $remoteIp];
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return isset($this->accepted[$user]) && hash_equals($this->accepted[$user], $password);
    }

    /** @return list<string> the users checked, in order */
    public function users(): array
    {
        return array_column($this->calls, 'user');
    }
}
