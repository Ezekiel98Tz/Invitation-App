<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitation_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guest_id')->constrained()->cascadeOnDelete();
            $table->enum('kind', ['invitation', 'reminder']);
            $table->enum('channel', ['mail', 'sms', 'whatsapp']);
            $table->enum('status', ['queued', 'sent', 'delivered', 'failed'])->default('queued');
            $table->string('provider_message_id')->nullable()->index();
            $table->text('error')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['guest_id', 'kind', 'channel']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitation_deliveries');
    }
};
