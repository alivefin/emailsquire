<?php

namespace App\Doctor;

use App\Doctor\Checks\GuideSchemaShape;
use Throwable;

/**
 * Base for checks that read the guide tables. Each one asks GuideSchemaShape
 * itself whether the schema is usable and skips when it isn't, so every
 * check stays independently runnable and still yields one result.
 */
abstract class GuideCheck extends BaseCheck
{
    public function __construct(protected readonly GuideSchemaShape $shape) {}

    final public function run(): CheckResult
    {
        try {
            $usable = $this->shape->problems() === [];
        } catch (Throwable) {
            $usable = false;
        }

        if (! $usable) {
            return $this->result(Status::Skip, 'Skipped: the guide schema check failed.');
        }

        return $this->check();
    }

    abstract protected function check(): CheckResult;
}
