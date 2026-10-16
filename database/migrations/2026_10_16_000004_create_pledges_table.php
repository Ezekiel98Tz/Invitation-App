<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pledge information records.
 *
 * A pledge is information management only: it records what a client *said* they
 * would give. The application never collects, holds, transfers, verifies or
 * settles money, so a pledge is never an instruction to move funds and an
 * outstanding pledge must never block invitations, RSVPs, credentials or
 * check-in.
 *
 * Amounts are INTEGER MINOR UNITS (e.g. cents) with an explicit ISO-4217
 * currency per pledge. Fulfillments inherit the pledge currency, so a pledge and
 * all of its fulfillments always share a single currency. Floats are never used.
 *
 * `pledger_name`/`pledger_email`/`pledger_phone` are a point-in-time snapshot:
 * they are copied from the client at entry time and survive deletion of the
 * linked guest or contact (both foreign keys use nullOnDelete).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pledges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guest_id')->nullable()->constrained('guests')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();

            // Snapshot of who made the pledge (never derived live).
            $table->string('pledger_name');
            $table->string('pledger_email')->nullable();
            $table->string('pledger_phone')->nullable();

            $table->char('currency', 3);
            $table->unsignedBigInteger('pledged_amount_minor');

            $table->text('note')->nullable();
            $table->string('source', 20)->default('manual'); // manual|import
            $table->string('status', 20)->default('active'); // active|cancelled

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['event_id', 'status']);
            $table->index(['user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pledges');
    }
};
