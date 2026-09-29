<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who created and last renamed a category, now that somebody does.
 *
 * Categories were planted by the seeder and nothing else, so there was no request behind a row and
 * nobody to stamp (the reason the table had no auditor columns). KOM-51 lets the platform create
 * and rename them, and every other written row here says who wrote it. Nullable, like everywhere:
 * the rows the seeder planted have no author, and the columns carry no foreign key (rule 3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('module_categories', function (Blueprint $table): void {
            $table->uuid('created_by')->nullable()->after('created_at');
            $table->uuid('updated_by')->nullable()->after('updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('module_categories', function (Blueprint $table): void {
            $table->dropColumn(['created_by', 'updated_by']);
        });
    }
};
