<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pledge import batches.
 *
 * A batch tracks a pledge spreadsheet (typically Excel/CSV) uploaded by the
 * client: the original filename, the detected columns, the chosen column
 * mapping and a summary of the outcome. This phase only creates the container;
 * the upload/preview/apply workflow is implemented separately.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pledge_import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('original_filename');
            $table->string('status', 20)->default('uploaded'); // uploaded|previewed|processing|completed|failed
            $table->json('columns')->nullable();
            $table->json('mapping')->nullable();
            $table->json('summary')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['event_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pledge_import_batches');
    }
};
