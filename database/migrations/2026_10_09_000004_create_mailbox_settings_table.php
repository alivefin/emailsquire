<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailbox_settings', function (Blueprint $table) {
            $table->string('mailbox_email', 100)->primary();
            $table->integer('domain_id');
            $table->string('display_name', 100)->nullable();
            $table->boolean('allow_user_forwarding')->nullable();
            $table->boolean('allow_user_aliases')->nullable();
            $table->boolean('allow_external_forwarding')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailbox_settings');
    }
};
