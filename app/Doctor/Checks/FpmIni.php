<?php

namespace App\Doctor\Checks;

use App\Doctor\BaseCheck;
use App\Doctor\CheckResult;
use App\Doctor\Status;

final class FpmIni extends BaseCheck
{
    public const REQUIRED = ['exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen'];

    public function id(): string
    {
        return 'runtime.fpm_ini';
    }

    public function title(): string
    {
        return 'PHP-FPM disable_functions and exception arguments';
    }

    public function run(): CheckResult
    {
        $path = config('emailsquire.paths.fpm_php_ini');
        if (! is_string($path) || ! is_file($path) || ! is_readable($path)) {
            return app()->environment('production')
                ? $this->result(Status::Fail, 'FPM php.ini not found or unreadable: '.(is_string($path) ? $path : ''))
                : $this->result(Status::Skip, 'FPM php.ini not found');
        }

        $ini = parse_ini_file($path, false, INI_SCANNER_RAW);
        $raw = is_array($ini) && is_string($ini['disable_functions'] ?? null) ? $ini['disable_functions'] : '';
        $disabled = array_map('trim', explode(',', trim($raw, " \t\"'")));

        $missing = array_values(array_diff(self::REQUIRED, $disabled));
        if ($missing !== []) {
            return $this->result(Status::Fail, 'disable_functions lacks: '.implode(', ', $missing));
        }

        $ignoreArgs = is_string($ini['zend.exception_ignore_args'] ?? null) ? strtolower(trim($ini['zend.exception_ignore_args'], " \t\"'")) : '';
        if (! in_array($ignoreArgs, ['on', '1'], true)) {
            return $this->result(Status::Warn, 'zend.exception_ignore_args is not On: stack traces would carry argument values such as passwords');
        }

        return $this->result(Status::Ok, 'All process functions are disabled and exception arguments are ignored');
    }
}
