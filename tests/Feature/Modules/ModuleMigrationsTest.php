<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ModuleAssertions;
use Tests\Support\TempTree;

// A loaded module's migrations run with the core's `migrate`, its tables carry its prefix, and
// `emailsquire:grants` covers them without the module declaring anything.

/** Runs $test with the fixture module loaded, then removes what its migration created. */
function withExampleMigrated(Closure $test): void
{
    bootWithModules(fixtureModule('example'));
    try {
        $test();
    } finally {
        Schema::dropIfExists('example_notes');
        DB::table('migrations')->where('migration', 'like', '%example_notes%')->delete();
    }
}

it('creates the module tables when the core migrates', function () {
    withExampleMigrated(function () {
        expect(Schema::hasTable('example_notes'))->toBeFalse();

        expect(Artisan::call('migrate', ['--force' => true]))->toBe(0);

        expect(Schema::hasTable('example_notes'))->toBeTrue();
        expect(Schema::hasColumns('example_notes', ['id', 'body', 'created_at', 'updated_at']))->toBeTrue();
    });
});

it('does not migrate modules that are not loaded', function () {
    expect(Artisan::call('migrate', ['--force' => true]))->toBe(0);

    expect(Schema::hasTable('example_notes'))->toBeFalse();
});

it('accepts migrations whose tables carry the prefix', function () {
    ModuleAssertions::tablesArePrefixed(base_path(fixtureModule('example').'/database/migrations'), 'example_');

    expect(Schema::hasTable('example_notes'))->toBeFalse();
});

it('rejects migrations that create a table outside the prefix', function () {
    $dir = TempTree::create();
    file_put_contents($dir.'/2026_11_01_000001_create_notes_table.php', <<<'PHP'
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                Schema::create('notes', fn (Blueprint $table) => $table->id());
            }

            public function down(): void
            {
                Schema::dropIfExists('notes');
            }
        };
        PHP);

    try {
        expect(fn () => ModuleAssertions::tablesArePrefixed($dir, 'example_'))->toThrow(Exception::class, 'notes');
        expect(Schema::hasTable('notes'))->toBeFalse();
    } finally {
        TempTree::remove($dir);
    }
});

it('grants read and write on module tables', function () {
    withExampleMigrated(function () {
        Artisan::call('migrate', ['--force' => true]);

        expect(Artisan::call('emailsquire:grants'))->toBe(0);

        expect(Artisan::output())->toContain('GRANT SELECT, INSERT, UPDATE, DELETE ON `'.DB::getDatabaseName().'`.`example_notes` TO ');
    });
});
