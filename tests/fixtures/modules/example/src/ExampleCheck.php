<?php

namespace EmailSquire\Modules\Example;

use App\Doctor\BaseCheck;
use App\Doctor\CheckResult;
use App\Doctor\Status;

/** The fixture module's doctor check. */
final class ExampleCheck extends BaseCheck
{
    public function id(): string
    {
        return 'example.ok';
    }

    public function title(): string
    {
        return 'The example module is healthy';
    }

    public function run(): CheckResult
    {
        return $this->result(Status::Ok, 'Example module OK');
    }
}
