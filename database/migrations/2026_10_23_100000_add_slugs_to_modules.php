<?php

declare(strict_types=1);

use App\Support\Links\Slug;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Give every module its permanent, printable part of a shared course address. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modules', function (Blueprint $table): void {
            $table->string('slug', Slug::MAXIMUM_LENGTH)->nullable()->after('name');
        });

        DB::table('modules')
            ->select(['id', 'name'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->each(function (object $row): void {
                $slug = Slug::make(
                    (string) $row->name,
                    static fn (string $slug): bool => DB::table('modules')->where('slug', $slug)->exists(),
                    'module',
                );

                DB::table('modules')->where('id', $row->id)->update(['slug' => $slug]);
            });

        Schema::table('modules', function (Blueprint $table): void {
            $table->string('slug', Slug::MAXIMUM_LENGTH)->nullable(false)->change();
            $table->unique('slug');
        });
    }

    public function down(): void
    {
        Schema::table('modules', function (Blueprint $table): void {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};
