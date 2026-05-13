<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('email')->index();
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('accepted_at')->nullable()->index();
            $table->timestamps();

            $table->index(['owner_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_invites');
    }
};
