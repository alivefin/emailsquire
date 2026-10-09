<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_settings', function (Blueprint $table) {
            $table->integer('domain_id')->primary();
            $table->string('domain_name', 50);
            $table->unsignedInteger('max_mailboxes')->nullable();
            $table->unsignedBigInteger('max_quota_bytes_total')->nullable();
            $table->boolean('allow_user_forwarding')->default(true);
            $table->boolean('allow_user_aliases')->default(false);
            $table->boolean('allow_external_forwarding')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_settings');
    }
};
