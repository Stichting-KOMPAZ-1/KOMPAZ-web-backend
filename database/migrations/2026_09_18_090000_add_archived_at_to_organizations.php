<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records that an organization is out of service.
 *
 * A timestamp rather than a flag, for the reason every other state here is one: "when" answers
 * "whether" as well, and an operator asking why somebody cannot sign in is asking when it started.
 *
 * Deliberately not `deleted_at`. Deleting an organization here is a hard delete that takes its
 * users with it; archiving is the reversible thing that was missing, and giving the two one column
 * would make the destructive one undoable by accident.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->timestamp('archived_at', 6)->nullable()->after('is_platform');

            // The roster leaves archived organizations out of every page unless it is asked for
            // them, so this column is read by the default listing rather than by an occasional
            // report.
            $table->index('archived_at');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropIndex(['archived_at']);
            $table->dropColumn('archived_at');
        });
    }
};
