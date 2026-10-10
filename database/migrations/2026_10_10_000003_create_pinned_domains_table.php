<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pinned_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('domain_id');
            $table->string('domain_name', 50);
            $table->unsignedSmallInteger('position');
            $table->unique(['user_id', 'domain_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pinned_domains');
    }
};
