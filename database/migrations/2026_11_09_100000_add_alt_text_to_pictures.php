<?php

declare(strict_types=1);

use App\Support\Modules\ContentRules;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** A description of every picture a reader sees, for whoever cannot see it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modules', function (Blueprint $table): void {
            $table->string('image_alt', ContentRules::MAXIMUM_IMAGE_ALT_LENGTH)->nullable()->after('image_byte_count');
        });

        Schema::table('e_learnings', function (Blueprint $table): void {
            $table->string('image_alt', ContentRules::MAXIMUM_IMAGE_ALT_LENGTH)->nullable()->after('image_byte_count');
        });

        Schema::table('content_blocks', function (Blueprint $table): void {
            $table->string('file_alt', ContentRules::MAXIMUM_IMAGE_ALT_LENGTH)->nullable()->after('file_byte_count');
        });

        Schema::table('carousel_slides', function (Blueprint $table): void {
            $table->string('file_alt', ContentRules::MAXIMUM_IMAGE_ALT_LENGTH)->nullable()->after('file_byte_count');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE modules
                ADD CONSTRAINT modules_alt_describes_a_picture
                    CHECK (image_alt IS NULL OR image_storage_key IS NOT NULL)
        SQL);

        // A video block's file is a video, which this does not describe.
        DB::statement(<<<'SQL'
            ALTER TABLE content_blocks
                ADD CONSTRAINT content_blocks_alt_describes_a_picture
                    CHECK (file_alt IS NULL OR (file_storage_key IS NOT NULL AND type IN ('Image', 'Instruction')))
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE content_blocks DROP CHECK content_blocks_alt_describes_a_picture');
        DB::statement('ALTER TABLE modules DROP CHECK modules_alt_describes_a_picture');

        Schema::table('carousel_slides', fn (Blueprint $table) => $table->dropColumn('file_alt'));
        Schema::table('content_blocks', fn (Blueprint $table) => $table->dropColumn('file_alt'));
        Schema::table('e_learnings', fn (Blueprint $table) => $table->dropColumn('image_alt'));
        Schema::table('modules', fn (Blueprint $table) => $table->dropColumn('image_alt'));
    }
};
