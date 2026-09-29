<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A course: a name, a picture, and a tree of chapters and steps underneath it.
 *
 * Independent of any module. One can be attached to several, to one, or to none at all, and
 * deleting a module only unlinks it — the product said so twice, once in the confirmation text an
 * operator reads and once in the acceptance criteria. That is why this is its own table with a
 * pivot beside it rather than something hanging off a module.
 *
 * Whether the name must be unique is an open product question. Until it is answered nothing here
 * enforces one, because a unique index added later is a migration while a unique index removed
 * later is a migration *and* an argument about the rows it already refused.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('e_learnings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 200);

            $table->string('image_storage_key', 512);
            $table->string('image_content_type', 100);
            $table->unsignedInteger('image_byte_count');

            $table->timestamp('created_at', 6);
            $table->uuid('created_by')->nullable();
            $table->timestamp('updated_at', 6);
            $table->uuid('updated_by')->nullable();

            $table->index('name');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('e_learnings');
    }
};
