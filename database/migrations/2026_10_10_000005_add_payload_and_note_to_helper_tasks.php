<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('helper_tasks', function (Blueprint $table) {
            $table->json('payload')->nullable()->after('domain_name');
            $table->string('note', 255)->nullable()->after('last_error');
        });
    }

    public function down(): void
    {
        Schema::table('helper_tasks', function (Blueprint $table) {
            $table->dropColumn(['payload', 'note']);
        });
    }
};
