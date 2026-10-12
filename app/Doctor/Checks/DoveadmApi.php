<?php

namespace App\Doctor\Checks;

use App\Doctor\BaseCheck;
use App\Doctor\CheckResult;
use App\Doctor\Status;
use App\MailServer\Dovecot\DoveadmClient;
use App\MailServer\Dovecot\DoveadmException;
use App\MailServer\Dovecot\DoveadmFailure;

final class DoveadmApi extends BaseCheck
{
    public function __construct(private readonly DoveadmClient $client) {}

    public function id(): string
    {
        return 'dovecot.doveadm_api';
    }

    public function title(): string
    {
        return 'Doveadm HTTP API';
    }

    public function isServerCheck(): bool
    {
        return true;
    }

    public function run(): CheckResult
    {
        $key = config('emailsquire.doveadm.api_key');
        if (! is_string($key) || $key === '') {
            return $this->result(Status::Fail, 'DOVEADM_API_KEY is not set');
        }

        try {
            $this->client->call('user', ['userMask' => ['doctor-probe@invalid']]);

            return $this->result(Status::Fail, 'the doctor probe address exists; cannot test doveadm command restrictions safely');
        } catch (DoveadmException $e) {
            if ($e->reason !== DoveadmFailure::CommandError || $e->exitCode !== 67) {
                return match ($e->reason) {
                    DoveadmFailure::Unauthorized => $this->result(Status::Fail, 'Doveadm rejected the API key'),
                    DoveadmFailure::Unreachable => $this->result(Status::Skip, 'Doveadm API unreachable: '.$e->getMessage()),
                    default => $this->result(Status::Fail, $e->getMessage()),
                };
            }
        }

        try {
            // Only probe a missing user, requesting metadata from a random, nonexistent mailbox.
            // Dovecot validates parameters before authorization: fetch uses field, not fields.
            $this->client->call('fetch', ['user' => 'doctor-probe@invalid', 'field' => ['guid'], 'query' => ['mailbox', bin2hex(random_bytes(16))]]);
        } catch (DoveadmException $e) {
            if ($e->reason !== DoveadmFailure::CommandError) {
                return match ($e->reason) {
                    DoveadmFailure::CommandUnauthorized => $this->result(Status::Ok, 'API reachable and key accepted'),
                    DoveadmFailure::Unauthorized => $this->result(Status::Fail, 'Doveadm rejected the API key'),
                    DoveadmFailure::Unreachable => $this->result(Status::Skip, 'Doveadm API unreachable: '.$e->getMessage()),
                    default => $this->result(Status::Fail, $e->getMessage()),
                };
            }
        }

        return $this->result(Status::Fail, 'doveadm key allows mail-reading commands; set doveadm_allowed_commands');
    }
}
