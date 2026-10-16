<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reported pledge fulfillments (append-only ledger).
 *
 * These rows record what a client *reports* they have given. They are not
 * payments: nothing is collected, held, transferred or verified. Multiple
 * partial entries are allowed, and over-fulfillment is permitted (it surfaces
 * as `over_fulfilled` rather than being clamped).
 *
 * History is never edited or deleted. A correction is an explicit compensating
 * entry that references the original through `reverses_fulfillment_id`; the
 * unique index guarantees an original can be reversed at most once. Amounts are
 * integer minor units in the pledge's currency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pledge_fulfillments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pledge_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount_minor');

            $table->string('method', 30)->nullable(); // cash|bank_transfer|mobile_money|cheque|other
            $table->string('method_detail')->nullable();
            $table->date('received_on')->nullable();
            $table->string('reference')->nullable();
            $table->text('note')->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('recorded'); // recorded|reversed

            // A compensating entry points back at the entry it reverses. The
            // original row is left untouched (history preserved).
            $table->foreignId('reverses_fulfillment_id')->nullable()
                ->constrained('pledge_fulfillments')->nullOnDelete();

            $table->timestamps();

            $table->unique('reverses_fulfillment_id');
            $table->index(['pledge_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pledge_fulfillments');
    }
};
