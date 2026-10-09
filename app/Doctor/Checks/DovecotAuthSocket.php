<?php

namespace App\Doctor\Checks;

use App\Doctor\BaseCheck;
use App\Doctor\CheckResult;
use App\Doctor\Status;
use App\MailServer\Dovecot\DovecotAuthClient;
use App\MailServer\Dovecot\DovecotAuthException;
use App\MailServer\Dovecot\DovecotAuthFailure;

final class DovecotAuthSocket extends BaseCheck
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
}
