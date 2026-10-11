<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the app stopped trusting a passkey (its signature counter went backwards, a sign of a cloned
 * authenticator). A disabled passkey can't sign in and is no second factor, but it stays listed until
 * its owner removes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('passkeys', function (Blueprint $table) {
            $table->timestamp('disabled_at')->nullable()->after('last_used_at');
        });
    }

    public function down(): void
    {
        Schema::table('passkeys', function (Blueprint $table) {
            $table->dropColumn('disabled_at');
        });
    }
};
