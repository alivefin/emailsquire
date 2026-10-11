<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a helper hands back to the app: the DKIM helper's public results (a new key's selector and
 * TXT record, an inspection's keys). Never a private key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('helper_tasks', function (Blueprint $table) {
            $table->json('result')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('helper_tasks', function (Blueprint $table) {
            $table->dropColumn('result');
        });
    }
};
