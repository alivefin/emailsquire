<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->timestamp('occurred_at', 6);
            $table->string('actor_type', 16);
            $table->string('actor_id', 255)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('action', 64);
            $table->string('object_type', 32);
            $table->string('object_key', 255);
            $table->string('domain_name', 50)->nullable();
            $table->json('changes')->nullable();
            $table->index(['domain_name', 'occurred_at']);
            $table->index(['actor_type', 'actor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }
};
