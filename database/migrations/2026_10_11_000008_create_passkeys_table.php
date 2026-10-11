<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * laravel/passkeys' `passkeys` table (its 2024_01_01_000000 migration), in the app schema, with two
 * changes for MariaDB: `credential_id` is base64url, which is case-sensitive, so it is ASCII with a
 * binary collation (the default *_ci collation would make two ids that differ only in case one); and
 * it is long enough for the longest id WebAuthn allows (1023 bytes, 1364 base64url characters).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('passkeys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('credential_id', 1364)->charset('ascii')->collation('ascii_bin')->unique();
            $table->json('credential');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passkeys');
    }
};
