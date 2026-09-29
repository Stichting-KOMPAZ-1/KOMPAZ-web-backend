<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which courses a module shows.
 *
 * A plain pivot with no payload of its own, and cascading from both sides — which is the whole
 * point of the table. Deleting either end removes the link and nothing else: a deleted module
 * leaves its courses standing, and a deleted course leaves its modules standing. Stating that as
 * two foreign keys means no code has to remember it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('e_learning_module', function (Blueprint $table): void {
            $table->foreignUuid('module_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('e_learning_id')->constrained()->cascadeOnDelete();

            // The pair is the key. Attaching the same course twice is not a second link, it is the
            // same one, and the database says so rather than the form that writes it.
            $table->primary(['module_id', 'e_learning_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('e_learning_module');
    }
};
