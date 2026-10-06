<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A module's description and source attribution are plain text again.
 *
 * They were written in the panel's rich-text editor for a few days, so modules saved in that time
 * hold the editor's markup — `<div>…<br>…</div>` — which a client showing text as written would
 * show as tags. Only a value that starts with a tag is touched: one written as plain text before
 * the editor arrived may contain a `<` of its own, and stripping it would eat the sentence.
 *
 * There is no way down. The markup carried nothing the product asked for, and putting tags back
 * around text would be inventing them.
 */
return new class extends Migration
{
    /** Each column, and whether it may be left empty — which markup with no text in it becomes. */
    private const array COLUMNS = ['description' => false, 'source_attribution' => true];

    public function up(): void
    {
        DB::table('modules')
            ->select(['id', ...array_keys(self::COLUMNS)])
            ->orderBy('id')
            ->each(function (object $module): void {
                $changes = [];

                foreach (self::COLUMNS as $column => $nullable) {
                    $value = $module->{$column};

                    if (! is_string($value) || ! str_starts_with(ltrim($value), '<')) {
                        continue;
                    }

                    $text = self::plainText($value);
                    $changes[$column] = $text === '' && $nullable ? null : $text;
                }

                if ($changes !== []) {
                    DB::table('modules')->where('id', $module->id)->update($changes);
                }
            });
    }

    public function down(): void {}

    /** The text an editor's markup shows, one line for each line break or block it drew. */
    private static function plainText(string $html): string
    {
        $text = preg_replace('~<br\s*/?>|</(div|p|h[1-6]|li|blockquote|pre)>~i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace("~\n{3,}~", "\n\n", $text) ?? $text);
    }
};
