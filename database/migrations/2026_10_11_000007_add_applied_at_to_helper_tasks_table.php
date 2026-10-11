<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the app last read a finished helper task's result into its own tables (the DKIM helper's
 * results into domain_dkim, App\MailServer\Dkim::sync()). Null until then, so each result is applied
 * once. The helpers never write it: their grants leave it out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('helper_tasks', function (Blueprint $table) {
            $table->timestamp('applied_at')->nullable()->after('result');
        });
    }

    public function down(): void
    {
        Schema::table('helper_tasks', function (Blueprint $table) {
            $table->dropColumn('applied_at');
        });
    }
};
