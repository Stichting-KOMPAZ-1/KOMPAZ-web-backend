<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The "Extra links" of a module, owned either by the module itself or by one organization's
 * activation of it.
 *
 * Shaped like {@see module_videos} and owned the same way, for the same reasons. It is the simpler
 * of the two because a link is always a URL: there is nothing to upload, so there is no second
 * constraint to write.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('module_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('module_activation_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('title', 200);
            $table->string('url', 2048);

            // Defaulted, because the panel's repeatable rows arrive without one: the key is a
            // UUIDv7 and therefore already in insertion order, so an unnumbered list keeps the
            // order it was typed in. An explicit position still takes precedence over it.
            $table->unsignedInteger('position')->default(0);

            $table->timestamp('created_at', 6);
            $table->uuid('created_by')->nullable();
            $table->timestamp('updated_at', 6);
            $table->uuid('updated_by')->nullable();

            $table->index(['module_id', 'position']);
            $table->index(['module_activation_id', 'position']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE module_links
                ADD CONSTRAINT module_links_one_owner
                    CHECK ((module_id IS NULL) <> (module_activation_id IS NULL))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('module_links');
    }
};
