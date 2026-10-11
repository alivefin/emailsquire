<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The latest DNS health check of each domain (DnsHealth); a new check replaces the row.
        Schema::create('domain_dns_checks', function (Blueprint $table) {
            $table->integer('domain_id')->primary();
            $table->string('domain_name', 50);
            $table->timestamp('checked_at');
            $table->json('results');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_dns_checks');
    }
};
