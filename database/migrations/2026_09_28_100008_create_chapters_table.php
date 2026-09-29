<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A section of a course. Belongs to exactly one, and goes with it.
 *
 * `is_summary` marks the chapter the front end draws differently — the "Samenvatting" of the
 * wireframe. A flag on the chapter rather than a separate kind of row, and deliberately not
 * limited to one per course: the product said several are allowed for now, so nothing here refuses
 * a second one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chapters', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('e_learning_id')->constrained()->cascadeOnDelete();

            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->boolean('is_summary')->default(false);

            // The order an operator arranged them in, which is the order the front end reads them
            // in. A new chapter is appended, so this is the count that came before it.
            $table->unsignedInteger('position');

            $table->timestamp('created_at', 6);
            $table->uuid('created_by')->nullable();
            $table->timestamp('updated_at', 6);
            $table->uuid('updated_by')->nullable();

            $table->index(['e_learning_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chapters');
    }
};
