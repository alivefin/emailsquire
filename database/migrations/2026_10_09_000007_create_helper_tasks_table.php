<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('helper_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('type', 32);
            $table->string('address', 100)->nullable();
            $table->string('domain_name', 50)->nullable();
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('run_after');
            $table->timestamps();
            $table->index(['status', 'run_after']);
            $table->index('address');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('helper_tasks');
    }
};
