<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The fixed taxonomy a module is filed under.
 *
 * A table rather than an enum, because the list is the product's to grow and a deployment should
 * not be what adds to it. Nothing in this phase creates one: the module form picks from what is
 * here, and the rows are planted by the seeder — which is why there is no `created_by` on them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_categories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 120);

            // Unique folded, like every other name here: two categories must not differ by case
            // alone, and the seeder is idempotent against this column rather than against the name
            // as typed.
            $table->string('normalized_name', 120);

            $table->timestamp('created_at', 6);
            $table->timestamp('updated_at', 6);

            $table->unique('normalized_name');

            // The picker orders by name, and so does the column shown in the modules table.
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_categories');
    }
};
