<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailbox_usage', function (Blueprint $table) {
            $table->string('email', 100)->primary();
            $table->unsignedBigInteger('used_kib');
            $table->unsignedBigInteger('limit_kib')->nullable();
            $table->timestamp('checked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailbox_usage');
    }
};
