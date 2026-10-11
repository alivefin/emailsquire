<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The DKIM keys the console knows per domain: the public record only (the private key never
        // leaves _rspamd's directory). Rows come from the DKIM helper's results.
        Schema::create('domain_dkim', function (Blueprint $table) {
            $table->id();
            $table->integer('domain_id');
            $table->string('domain_name', 50);
            $table->string('selector', 32);
            $table->text('txt');
            $table->string('state', 10)->default('pending'); // pending | active | retired
            $table->timestamps();

            $table->unique(['domain_id', 'selector']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_dkim');
    }
};
