<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Physical attendance / check-in records.
 *
 * Attendance is a distinct, explicit operator action — never inferred from an
 * RSVP, a QR scan or an invitation verification. A nullable `active_key`
 * (= guest id while valid) plus a unique index enforces at most one *active*
 * check-in per guest; reversal sets it null so a corrected check-in can be
 * recorded without destroying the original row (history is preserved).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guest_id')->constrained()->cascadeOnDelete();
            $table->foreignId('credential_id')->nullable()->constrained('invitation_credentials')->nullOnDelete();
            $table->foreignId('rsvp_id')->nullable()->constrained('rsvps')->nullOnDelete();

            $table->timestamp('checked_in_at');
            $table->foreignId('checked_in_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('method', 20)->default('code'); // qr|code|manual|override
            $table->unsignedInteger('party_size')->default(1);
            $table->string('status', 20)->default('valid'); // valid|reversed
            $table->string('note')->nullable();

            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reversal_reason')->nullable();
            $table->unsignedBigInteger('active_key')->nullable();

            $table->timestamps();

            $table->unique('active_key');
            $table->index(['event_id', 'status']);
            $table->index(['guest_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
