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
        } catch (DoveadmException $e) {
            return match (true) {
                $e->reason === DoveadmFailure::CommandError && $e->exitCode === 67 => $this->result(Status::Ok, 'API reachable and key accepted'),
                $e->reason === DoveadmFailure::Unauthorized => $this->result(Status::Fail, 'Doveadm rejected the API key'),
                $e->reason === DoveadmFailure::Unreachable => $this->result(Status::Skip, 'Doveadm API unreachable: '.$e->getMessage()),
                default => $this->result(Status::Fail, $e->getMessage()),
            };
        }

        return $this->result(Status::Ok, 'API reachable and key accepted');
    }
}
