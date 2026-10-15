<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Optional celebration membership for an event.
     *
     * Nullable so every existing event remains a valid, standalone occasion.
     * The foreign key uses nullOnDelete so removing a celebration detaches its
     * events instead of cascading — events (and their guests, RSVPs and
     * delivery history) must never be deleted as a side effect of removing a
     * celebration. The API detaches explicitly as well, so the column is clear
     * even on database engines without foreign-key enforcement.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->foreignId('celebration_id')
                ->nullable()
                ->after('user_id')
                ->constrained('celebrations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('celebration_id');
        });
    }
};
