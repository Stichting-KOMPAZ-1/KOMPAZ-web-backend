<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A unit of instruction, written once by the platform and switched on per organization.
 *
 * Deliberately **not** unique by name, folded or otherwise, which is the one place this table
 * departs from every other named thing here: two organizations can be given modules that mean
 * different things under the same word, and the product asked for the name to stay free. The
 * folded column that carries uniqueness elsewhere is therefore absent rather than merely
 * unindexed — a column nothing enforces would read like an oversight.
 *
 * The picture is optional — some modules have none — and lives here rather than in a table of its
 * own. An organization's logo earned a row because it is read back through a route that has to
 * find it by organization and answer a placeholder when there is none; a module's picture is read
 * with the module or not at all, so a second table would buy a join and nothing else. Absence is
 * therefore three nulls rather than a missing row, and the constraint below is what keeps "three
 * nulls" from meaning "somebody wrote two of them".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 200);

            // Restricted rather than cascaded: a category is a label on many modules, and deleting
            // one should be refused while any module still wears it rather than silently take the
            // modules with it.
            $table->foreignUuid('category_id')->constrained('module_categories')->restrictOnDelete();

            $table->text('description');

            // "Bronvermelding" — where the content came from. Optional, and prose rather than a
            // link, so it is text and not a URL.
            $table->text('source_attribution')->nullable();

            // Purely informative: the product was explicit that this changes nothing about who
            // sees the module. What decides that is the activation rows.
            $table->string('status', 40);

            // Where the picture is, and what its bytes were recognized as. Stored because it is
            // what a later response is labelled with — never taken from the upload's own header.
            // All three together or none of them; see the constraint below.
            $table->string('image_storage_key', 512)->nullable();
            $table->string('image_content_type', 100)->nullable();
            $table->unsignedInteger('image_byte_count')->nullable();

            $table->timestamp('created_at', 6);
            $table->uuid('created_by')->nullable();
            $table->timestamp('updated_at', 6);
            $table->uuid('updated_by')->nullable();

            // The panel searches on name and orders newest-first, and does both on every page of
            // the modules table.
            $table->index('name');
            $table->index('created_at');
        });

        // A picture is three columns, and they are only ever true together. Without this, a write
        // that set the key and forgot the media type would leave a row that has a picture by one
        // reading and none by another — and the reading that wins would be whichever line of PHP
        // asked first.
        DB::statement(<<<'SQL'
            ALTER TABLE modules
                ADD CONSTRAINT modules_image_is_whole
                    CHECK ((image_storage_key IS NULL) = (image_content_type IS NULL)
                       AND (image_storage_key IS NULL) = (image_byte_count IS NULL))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('modules');
    }
};
