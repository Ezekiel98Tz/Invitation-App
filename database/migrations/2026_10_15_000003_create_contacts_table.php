<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Account-scoped reusable contact identities.
     *
     * A contact belongs to the account owner (`user_id`), matching the ownership
     * model used by `events` and `celebrations`: the owner manages their own
     * records and staff operate within their owner's account. Contacts are never
     * visible across unrelated accounts.
     *
     * `normalized_email` / `normalized_phone` are match keys (intended to hold a
     * lowercased email and a digit-only phone, populated by a later phase). They
     * are nullable and their uniqueness is scoped per account. This relies on the
     * standard SQL behaviour that NULL values are distinct, so any number of
     * contacts may omit an email or phone without conflicting. The project uses
     * MySQL/MariaDB (including the isolated testing database), which treats
     * multiple NULLs as non-conflicting in a UNIQUE index; SQLite behaves the
     * same way should it ever be used.
     *
     * Additive only: no existing table, column, constraint, id or token is
     * touched, and the column is not populated in this phase.
     */
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('normalized_email')->nullable();
            $table->string('normalized_phone')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'normalized_email']);
            $table->unique(['user_id', 'normalized_phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
