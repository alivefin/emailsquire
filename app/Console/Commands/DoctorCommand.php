<?php

namespace App\Console\Commands;

use App\Doctor\CheckResult;
use App\Doctor\Doctor;
use App\Doctor\Status;
use Illuminate\Console\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

final class DoctorCommand extends Command
{
    protected $signature = 'emailsquire:doctor
        {--json : Print the results as JSON}
        {--require-server : Treat a skipped server check as a failure}';

    protected $description = 'Diagnose the database, the guide schema and the mail server';

    public function handle(Doctor $doctor): int
    {
        $results = $doctor->run((bool) $this->option('require-server'));

        if ($this->option('json')) {
            $this->printJson($results);
        } else {
            $this->printTable($results);
        }

        foreach ($results as $result) {
            if ($result->status === Status::Fail) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    /** @param  list<CheckResult>  $results */
    private function printJson(array $results): void
    {
        $rows = array_map(fn (CheckResult $r): array => [
            'id' => $r->id,
            'status' => $r->status->value,
            'title' => $r->title,
            'detail' => $r->detail,
            'items' => $r->items,
        ], $results);

        $json = json_encode(
            $rows,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        );

        // Raw, so guide data that looks like <tags> is not interpreted by the formatter.
        $this->output->writeln($json, OutputInterface::OUTPUT_RAW);
    }

    /** @param  list<CheckResult>  $results */
    private function printTable(array $results): void
    {
        $rows = [];

        foreach ($results as $result) {
            $rows[] = [
                $this->statusLabel($result->status),
                OutputFormatter::escape($result->title),
                OutputFormatter::escape($result->detail ?? ''),
            ];

            foreach ($result->items as $item) {
                $rows[] = ['', '', '  - '.OutputFormatter::escape($item)];
            }
        }

        $this->table(['Status', 'Check', 'Detail'], $rows);
    }

    private function statusLabel(Status $status): string
    {
        return match ($status) {
            Status::Ok => '<info>ok</info>',
            Status::Warn => '<comment>warn</comment>',
            Status::Fail => '<error>fail</error>',
            Status::Skip => 'skip',
        };
    }
}
