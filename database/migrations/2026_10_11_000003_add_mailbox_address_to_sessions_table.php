<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The portal mailbox a session is signed in to (App\Auth\GuardSessionHandler), so a mailbox's
     * other portal sessions can be ended without reading payloads. `user_id` stays the console user's.
     */
    public function up(): void
    {
        Schema::table('sessions', function (Blueprint $table) {
            $table->string('mailbox_address', 100)->nullable()->index()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('sessions', function (Blueprint $table) {
            $table->dropIndex(['mailbox_address']);
            $table->dropColumn('mailbox_address');
        });
    }
};
