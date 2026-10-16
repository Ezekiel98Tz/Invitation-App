<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Allow credential delivery attempts to be recorded with their own kind so
 * entry-code sends appear in the Delivery Lab and delivery history without
 * being conflated with invitations/reminders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitation_deliveries', function (Blueprint $table) {
            $table->enum('kind', ['invitation', 'reminder', 'credential'])->change();
        });
    }

    public function down(): void
    {
        Schema::table('invitation_deliveries', function (Blueprint $table) {
            $table->enum('kind', ['invitation', 'reminder'])->change();
        });
    }
};
