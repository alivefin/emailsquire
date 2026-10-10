<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an invitation mail says besides the link: the inviter's note, the language it goes out in and
 * the name the inviter gave the invitee. The email index lets a create lock the rows (and the gap) of
 * one address, so two creates for the same address and target cannot both pass the duplicate check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->string('note', 500)->nullable()->after('role');
            $table->string('locale', 8)->default('en')->after('note');
            $table->string('name', 100)->nullable()->after('locale');
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->dropIndex(['email']);
            $table->dropColumn(['note', 'locale', 'name']);
        });
    }
};
