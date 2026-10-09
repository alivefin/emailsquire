<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disabled_mailboxes', function (Blueprint $table) {
            $table->string('mailbox_email', 100)->primary();
            $table->integer('domain_id');
            $table->string('saved_password', 150);
            $table->foreignId('disabled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('disabled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disabled_mailboxes');
    }
};
