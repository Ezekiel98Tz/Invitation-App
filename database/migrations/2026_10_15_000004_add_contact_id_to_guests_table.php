<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Optional link from an event guest to a reusable account contact.
     *
     * Nullable so every existing guest row remains valid with
     * `contact_id = null`. `nullOnDelete` means deleting a contact detaches its
     * guests instead of deleting them, so their RSVPs, invitation deliveries and
     * invitation tokens are never affected.
     *
     * The foreign key also provides the supporting index. Additive only: no
     * existing guest column, constraint, id or token is changed, and the column
     * is not populated in this phase.
     */
    public function up(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->foreignId('contact_id')
                ->nullable()
                ->after('event_id')
                ->constrained('contacts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contact_id');
        });
    }
};
