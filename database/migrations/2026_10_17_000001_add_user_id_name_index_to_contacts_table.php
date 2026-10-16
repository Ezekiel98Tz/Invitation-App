<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supporting index for the account-scoped contact list.
 *
 * `ContactController::index()` runs `WHERE user_id = ? ORDER BY name, id` on
 * every page and search request. EXPLAIN on the isolated testing database showed
 * the account predicate satisfied by the leading column of the existing
 * `(user_id, normalized_email)` unique index, while the ORDER BY produced
 * `Using filesort`. This additive index covers both the predicate and the sort.
 *
 * Additive only: no column, foreign key, unique constraint, id or token is
 * changed, and the existing normalized-identifier uniqueness is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->index(['user_id', 'name', 'id'], 'contacts_user_id_name_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex('contacts_user_id_name_id_index');
        });
    }
};
