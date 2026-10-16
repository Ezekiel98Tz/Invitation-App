<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pledge import lineage.
 *
 * Adds a nullable `import_batch_id` to `pledges` so every pledge created by a
 * spreadsheet import can be traced back to the `pledge_import_batches` row it
 * came from. This is additive only:
 *
 *  - Existing (manual) pledges keep a NULL value.
 *  - Deleting a batch keeps the pledge and simply clears the lineage pointer
 *    (`nullOnDelete`), so pledge history is never lost.
 *  - Pledges are information records only; lineage has no effect on balances,
 *    invitations, RSVPs, credentials or check-in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pledges', function (Blueprint $table) {
            $table->foreignId('import_batch_id')
                ->nullable()
                ->after('created_by')
                ->constrained('pledge_import_batches')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pledges', function (Blueprint $table) {
            $table->dropConstrainedForeignId('import_batch_id');
        });
    }
};
