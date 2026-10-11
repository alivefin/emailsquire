<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The activity log reads `audit_log` newest first (`occurred_at desc, id desc`); without a filter, as a
 * server admin opens it, that order needs an index of its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_log', function (Blueprint $table) {
            $table->index(['occurred_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_log', function (Blueprint $table) {
            $table->dropIndex(['occurred_at', 'id']);
        });
    }
};
