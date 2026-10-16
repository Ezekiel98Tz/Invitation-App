<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Event-entry credentials.
 *
 * Deliberately separate from `guests.invite_token` (which remains the RSVP
 * link bearer token). One credential row exists per guest per event; a nullable
 * `active_key` (= guest id while current) plus a unique index guarantees at most
 * one current credential per guest even under concurrent issuance. The secret is
 * stored hashed (`code_hash`, deterministic HMAC for lookup) and, for deliberate
 * access-controlled re-display/re-send, encrypted at rest (`code_encrypted`).
 * No plaintext secret column exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitation_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guest_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20)->default('short_code');
            $table->string('status', 20)->default('active'); // active|revoked|expired|issued

            $table->string('code_hash', 64)->nullable();
            $table->text('code_encrypted')->nullable();

            $table->foreignId('replaces_id')->nullable()->constrained('invitation_credentials')->nullOnDelete();
            $table->unsignedBigInteger('active_key')->nullable();

            $table->timestamp('issued_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('revoke_reason')->nullable();

            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique('active_key');
            $table->unique('code_hash');
            $table->index(['event_id', 'status']);
            $table->index(['guest_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitation_credentials');
    }
};
