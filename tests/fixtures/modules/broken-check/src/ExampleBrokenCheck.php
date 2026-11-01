<?php

namespace EmailSquire\Modules\BrokenCheck;

use App\Doctor\BaseCheck;
use App\Doctor\CheckResult;
use App\Doctor\Status;
use RuntimeException;

/** A doctor check whose constructor throws, so resolving it fails. */
final class ExampleBrokenCheck extends BaseCheck
{
    public function __construct()
    {
        throw new RuntimeException('The broken check cannot be built.');
    }

    public function id(): string
    {
        return 'broken-check.never';
    }

    public function title(): string
    {
        return 'Never reached';
    }

    public function run(): CheckResult
    {
        return $this->result(Status::Ok, 'unreachable');
    }
}
