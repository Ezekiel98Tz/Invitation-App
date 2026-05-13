<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rsvps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guest_id')->constrained('guests')->cascadeOnDelete();
            $table->unsignedInteger('attending_count')->default(1);
            $table->json('answers')->nullable();
            $table->timestamps();

            $table->unique('guest_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rsvps');
    }
};
