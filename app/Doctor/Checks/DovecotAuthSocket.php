<?php

namespace App\Doctor\Checks;

use App\Doctor\BaseCheck;
use App\Doctor\CheckResult;
use App\Doctor\Status;
use App\MailServer\Dovecot\DovecotAuthClient;
use App\MailServer\Dovecot\DovecotAuthException;
use App\MailServer\Dovecot\DovecotAuthFailure;

/**
 * Not final: tests override isRoot() to exercise the root-only owner and mode check.
 */
class DovecotAuthSocket extends BaseCheck
{
    public function __construct(private readonly DovecotAuthClient $client) {}

    public function id(): string
    {
        return 'dovecot.auth_socket';
    }

    public function title(): string
    {
        return 'Dovecot auth socket';
    }

    public function isServerCheck(): bool
    {
        return true;
    }

    public function run(): CheckResult
    {
        // Root bypasses the socket's 0600 mode, so under sudo a handshake proves nothing about
        // whether the app user can connect: check the owner and mode the install steps prescribe.
        $socket = config('emailsquire.dovecot_auth.socket');
        if ($this->isRoot() && is_string($socket) && file_exists($socket)) {
            $expected = config()->string('emailsquire.app_user');
            $owner = MailRoot::ownerName($socket) ?? 'an unknown user';
            $mode = @fileperms($socket);
            $mode = $mode === false ? null : $mode & 0777;

            if ($owner !== $expected || $mode !== 0600) {
                return $this->result(Status::Fail, sprintf(
                    '%s must be owned by %s with mode 0600, but is owned by %s with mode %s',
                    $socket, $expected, $owner, $mode === null ? 'unknown' : sprintf('%04o', $mode),
                ));
            }
        }

        try {
            $this->client->handshake();
        } catch (DovecotAuthException $e) {
            return match ($e->reason) {
                DovecotAuthFailure::Unreachable => $this->result(Status::Skip, 'Auth socket unreachable: '.$e->getMessage()),
                DovecotAuthFailure::PermissionDenied => $this->result(Status::Fail, 'socket must be owned by the user running the app: '.$e->getMessage()),
                default => $this->result(Status::Fail, $e->getMessage()),
            };
        }

        return $this->result(Status::Ok, 'Handshake succeeded');
    }

    protected function isRoot(): bool
    {
        return posix_geteuid() === 0;
    }
}
