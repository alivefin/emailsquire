<?php

use App\Doctor\Checks\MailQueueWorker;
use App\Doctor\Checks\MailSmtp;
use App\Doctor\Doctor;
use App\Doctor\SmtpProbe;
use App\Doctor\Status;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => isolateDoctorFromHost());

function queueJob(int $minutesOld): void
{
    $at = now()->subMinutes($minutesOld)->getTimestamp();
    DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => $at, 'created_at' => $at]);
}

/** A throwaway server in a child process; it greets one client with $greeting and exits. */
function greetingServer(string $greeting): array
{
    $script = '$s = stream_socket_server("tcp://127.0.0.1:0"); '
        .'echo substr(strrchr(stream_socket_get_name($s, false), ":"), 1), "\n"; '
        .'$c = stream_socket_accept($s, 10); if ($c) { fwrite($c, '.var_export($greeting."\r\n", true).'); sleep(1); }';
    $process = proc_open([PHP_BINARY, '-r', $script], [1 => ['pipe', 'w']], $pipes);
    $port = (int) trim((string) fgets($pipes[1]));

    return [$process, $port];
}

function probeAt(int $port): void
{
    config()->set(['mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => $port]);
    app()->instance(SmtpProbe::class, new SmtpProbe);
}

it('is Ok with an empty queue and warns about a job waiting 11 minutes', function () {
    expect(app(MailQueueWorker::class)->run()->status)->toBe(Status::Ok);

    queueJob(5);
    expect(app(MailQueueWorker::class)->run()->status)->toBe(Status::Ok);

    queueJob(11);
    $result = app(MailQueueWorker::class)->run();
    expect($result->status)->toBe(Status::Warn)
        ->and($result->detail)->toContain('emailsquire-queue.service');
});

it('does not warn about a delayed job that is not due yet', function () {
    DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null,
        'available_at' => now()->addHour()->getTimestamp(), 'created_at' => now()->subHour()->getTimestamp()]);

    expect(app(MailQueueWorker::class)->run()->status)->toBe(Status::Ok);
});

it('is a database-side check and the smtp check is a server check', function () {
    expect(app(MailQueueWorker::class)->isServerCheck())->toBeFalse()
        ->and(app(MailSmtp::class)->isServerCheck())->toBeTrue()
        ->and(app(MailQueueWorker::class)->id())->toBe('mail.queue_worker')
        ->and(app(MailSmtp::class)->id())->toBe('mail.smtp')
        ->and(Doctor::CHECKS)->toContain(MailQueueWorker::class, MailSmtp::class);
});

it('fails when nothing listens on the smtp port', function () {
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);
    fclose($server);

    probeAt($port);

    expect(app(MailSmtp::class)->run()->status)->toBe(Status::Fail);
});

it('is Ok against a server that greets with 220', function () {
    [$process, $port] = greetingServer('220 test ESMTP');
    probeAt($port);

    expect(app(MailSmtp::class)->run()->status)->toBe(Status::Ok);
    proc_terminate($process);
    proc_close($process);
});

it('warns when the banner is not 220', function () {
    [$process, $port] = greetingServer('554 go away');
    probeAt($port);

    $result = app(MailSmtp::class)->run();
    expect($result->status)->toBe(Status::Warn)->and($result->detail)->toContain('554');
    proc_terminate($process);
    proc_close($process);
});
