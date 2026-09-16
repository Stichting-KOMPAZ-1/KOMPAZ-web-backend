<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Points Nova's polymorphic keys at the kind of identifier this application actually issues.
 *
 * Nova's own migrations declare these columns with `morphs()`, which is a bigint unless the
 * schema builder's default morph key type says otherwise, and every model here is keyed by UUID.
 * A UUID does not fit a bigint, so MySQL refused the insert with error 1265 and every create,
 * update and delete through the panel answered 500 — reading stayed fine, which is why a
 * read-mostly panel hid it. The same mistake as Sanctum's published `morphs()`, in a package whose
 * migrations are not published at all.
 *
 * `AppServiceProvider` now sets the default morph key type, so on a fresh database Nova's
 * migrations build these columns correctly and every change below is skipped. This exists for the
 * databases that already ran them.
 */
return new class extends Migration
{
    /**
     * The polymorphic keys Nova declares, and whether each one may be absent.
     *
     * @var array<string, array<string, bool>>
     */
    private const array MORPH_KEYS = [
        'action_events' => ['actionable_id' => false, 'target_id' => false, 'model_id' => true],
        'nova_notifications' => ['notifiable_id' => false],
        'nova_field_attachments' => ['attachable_id' => false],
    ];

    public function up(): void
    {
        foreach (self::MORPH_KEYS as $table => $columns) {
            $pending = $this->columnsNotOfType($table, 'char', $columns);

            if ($pending === []) {
                continue;
            }

            Schema::table($table, static function (Blueprint $blueprint) use ($pending): void {
                foreach ($pending as $column => $nullable) {
                    $blueprint->uuid($column)->nullable($nullable)->change();
                }
            });
        }
    }

    /**
     * Restores the shape Nova's migrations left behind.
     *
     * Only ever meaningful on empty tables: a UUID cannot be represented as a bigint, so rolling
     * back over a panel that has recorded anything fails loudly rather than discarding the log.
     */
    public function down(): void
    {
        foreach (self::MORPH_KEYS as $table => $columns) {
            $pending = $this->columnsNotOfType($table, 'bigint', $columns);

            if ($pending === []) {
                continue;
            }

            Schema::table($table, static function (Blueprint $blueprint) use ($pending): void {
                foreach ($pending as $column => $nullable) {
                    $blueprint->unsignedBigInteger($column)->nullable($nullable)->change();
                }
            });
        }
    }

    /**
     * Which of these columns are not already the wanted type, so that a database built with the
     * default morph key type in place is left untouched.
     *
     * @param  array<string, bool>  $columns
     * @return array<string, bool>
     */
    private function columnsNotOfType(string $table, string $type, array $columns): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        return array_filter(
            $columns,
            static fn (bool $nullable, string $column): bool => Schema::getColumnType($table, $column) !== $type,
            ARRAY_FILTER_USE_BOTH,
        );
    }
};
