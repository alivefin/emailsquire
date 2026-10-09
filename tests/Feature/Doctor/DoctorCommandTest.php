<?php

use App\Doctor\Check;
use App\Doctor\CheckResult;
use App\Doctor\Checks\DatabaseConnection;
use App\Doctor\Doctor;
use App\Doctor\Status;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\GuideFixture;

it('exits 0 on a clean trixie install', function () {
    GuideFixture::load('trixie');

    expect(Artisan::call('emailsquire:doctor'))->toBe(0);

    $output = Artisan::output();
    expect($output)->toContain('Status')->toContain('Check')->toContain('Detail')
        ->toContain('ok');
});

it('exits 1 when any check fails and lists the items below it', function () {
    GuideFixture::load('trixie', 'anomalies');

    expect(Artisan::call('emailsquire:doctor'))->toBe(1);
    expect(Artisan::output())->toContain('fail')->toContain('PLAIN-MD5')->toContain('jörg@example.org');
});

it('prints one JSON object per check in Doctor::CHECKS order', function () {
    GuideFixture::load('trixie', 'anomalies');

    $exit = Artisan::call('emailsquire:doctor', ['--json' => true]);
    $decoded = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($exit)->toBe(1)
        ->and($decoded)->toHaveCount(count(Doctor::CHECKS));

    $expectedIds = array_map(fn (string $class) => app($class)->id(), Doctor::CHECKS);
    expect(array_column($decoded, 'id'))->toBe($expectedIds);

    foreach ($decoded as $row) {
        expect(array_keys($row))->toBe(['id', 'status', 'title', 'detail', 'items']);
    }
});

it('turns a skipped server check into a failure with --require-server', function () {
    GuideFixture::load('trixie');

    app()->bind(DatabaseConnection::class, fn () => new class implements Check
    {
        public function id(): string
        {
            return 'server.probe';
        }

        public function title(): string
        {
            return 'Server probe';
        }

        public function isServerCheck(): bool
        {
            return true;
        }

        public function run(): CheckResult
        {
            return new CheckResult($this->id(), Status::Skip, $this->title(), 'not on a mail server');
        }
    });

    expect(app(Doctor::class)->run()[0]->status)->toBe(Status::Skip)
        ->and(app(Doctor::class)->run(requireServer: true)[0]->status)->toBe(Status::Fail)
        ->and(Artisan::call('emailsquire:doctor'))->toBe(0)
        ->and(Artisan::call('emailsquire:doctor', ['--require-server' => true]))->toBe(1);
});
