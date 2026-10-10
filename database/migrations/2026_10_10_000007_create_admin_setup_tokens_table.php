<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Setup links (`admin_setup` broker) get a table of their own, so a forgotten-password token (`users`
// broker, password_reset_tokens) never passes as a setup token and the other way round: the brokers
// look a token up by email alone, and setup signs the user in without two-factor. Until now only setup
// links used password_reset_tokens, so every row there moves.
return new class extends Migration
{
    public function up(): void
    {
        // MariaDB commits DDL at once: if a run stopped after the create, the re-run skips it.
        if (! Schema::hasTable('admin_setup_tokens')) {
            Schema::create('admin_setup_tokens', function (Blueprint $table) {
                $table->string('email')->primary();
                $table->string('token');
                $table->timestamp('created_at')->nullable();
            });
        }

        // Or-ignore: a re-run after a stop between the copy and the delete copies nothing twice.
        DB::table('admin_setup_tokens')->insertOrIgnoreUsing(
            ['email', 'token', 'created_at'],
            DB::table('password_reset_tokens')->select(['email', 'token', 'created_at']),
        );
        DB::table('password_reset_tokens')->delete();
    }

    public function down(): void
    {
        // Reset tokens are short-lived and can be asked for again; setup links move back.
        DB::table('password_reset_tokens')->delete();
        DB::table('password_reset_tokens')->insertUsing(
            ['email', 'token', 'created_at'],
            DB::table('admin_setup_tokens')->select(['email', 'token', 'created_at']),
        );
        Schema::drop('admin_setup_tokens');
    }
};
