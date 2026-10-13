<?php

declare(strict_types=1);

use App\Support\Links\Slug;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The readable halves of a shared e-learning's address, `/{organization}/{e-learning}` (KOM-95).
 *
 * Every existing organization and course is given one from its name, oldest first, so of two that
 * share a name the older one keeps the plain slug. From here on the models make one on creation
 * and nothing changes it: it is printed in QR codes. Unique, because it is how a visitor with no
 * account is told which organization and which course they arrived at.
 */
return new class extends Migration
{
    /** Each table, and the word a slug falls back on when a name gives it nothing to use. */
    private const array TABLES = ['organizations' => 'organisatie', 'e_learnings' => 'e-learning'];

    public function up(): void
    {
        foreach (self::TABLES as $table => $fallback) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->string('slug', Slug::MAXIMUM_LENGTH)->nullable()->after('name');
            });

            DB::table($table)
                ->select(['id', 'name'])
                ->orderBy('created_at')
                ->orderBy('id')
                ->each(function (object $row) use ($table, $fallback): void {
                    $slug = Slug::make(
                        (string) $row->name,
                        static fn (string $slug): bool => DB::table($table)->where('slug', $slug)->exists(),
                        $fallback,
                    );

                    DB::table($table)->where('id', $row->id)->update(['slug' => $slug]);
                });

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->string('slug', Slug::MAXIMUM_LENGTH)->nullable(false)->change();
                $blueprint->unique('slug');
            });
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropUnique(['slug']);
                $blueprint->dropColumn('slug');
            });
        }
    }
};
