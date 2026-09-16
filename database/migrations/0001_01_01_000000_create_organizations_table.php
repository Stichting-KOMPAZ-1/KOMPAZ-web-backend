<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 200);

            // The unique index is on the folded name, not on the name itself, so two organizations
            // cannot differ by case alone. The name keeps a plain index of its own: it is what the
            // roster orders by.
            $table->string('normalized_name', 200);
            $table->boolean('is_platform')->default(false);

            $table->timestamp('created_at', 6);
            $table->uuid('created_by')->nullable();
            $table->timestamp('updated_at', 6);
            $table->uuid('updated_by')->nullable();

            $table->unique('normalized_name');
            $table->index('name');
        });

        // At most one organization runs the platform. PostgreSQL stated this as a partial unique
        // index; MySQL has none, so the same guarantee is carried by a generated column that is
        // NULL for every organization that is not the platform one — NULLs do not collide in a
        // unique index, so the constraint applies to exactly the row it is about.
        DB::statement(<<<'SQL'
            ALTER TABLE organizations
                ADD COLUMN platform_marker TINYINT
                    GENERATED ALWAYS AS (CASE WHEN is_platform THEN 1 ELSE NULL END) STORED,
                ADD UNIQUE INDEX organizations_platform_marker_unique (platform_marker)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
