<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A module switched on for one organization — the "Actief bij" of the module form, seen from the
 * other end.
 *
 * A row with a key of its own rather than a bare pivot, because it is the thing an organization
 * administrator adds to: their own videos, their own links and, above all, their own contact
 * details hang off this row and not off the module, which is shared. Without its own identifier
 * each of those would have to carry the pair instead.
 *
 * It also carries when the switch was thrown, because the organization administrator's table is
 * ordered by it: a module that became available to them yesterday is the one they still have to
 * fill in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_activations', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('module_id')->constrained()->cascadeOnDelete();

            // Cascading rather than restricting: an organization that is deleted takes its users
            // with it already, and leaving behind the modules it had switched on would leave rows
            // naming a tenant that no longer exists.
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();

            $table->timestamp('activated_at', 6);

            $table->timestamp('created_at', 6);
            $table->uuid('created_by')->nullable();
            $table->timestamp('updated_at', 6);
            $table->uuid('updated_by')->nullable();

            // One activation per pair. Switching a module on twice for the same organization is
            // the same activation, and doing so must not reset the date their table is ordered by.
            $table->unique(['module_id', 'organization_id']);

            // How the organization administrator's table reads it: their own rows, newest first.
            $table->index(['organization_id', 'activated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_activations');
    }
};
