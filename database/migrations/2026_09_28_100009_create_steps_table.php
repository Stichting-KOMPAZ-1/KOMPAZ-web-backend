<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One screen of a chapter: a name, and the blocks that make up what is on it.
 *
 * The row holds almost nothing, because almost everything about a step is its content, and content
 * is a list of blocks rather than a set of columns. What lives here is what a chapter's table
 * shows and what the order is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('steps', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('chapter_id')->constrained()->cascadeOnDelete();

            $table->string('name', 200);
            $table->unsignedInteger('position');

            $table->timestamp('created_at', 6);
            $table->uuid('created_by')->nullable();
            $table->timestamp('updated_at', 6);
            $table->uuid('updated_by')->nullable();

            $table->index(['chapter_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('steps');
    }
};
