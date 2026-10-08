<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A chapter's description is plain text again, as a module's became two days before it.
 *
 * Chapters saved while it was written in the panel's rich-text editor hold the editor's markup —
 * `<div>…<br>…</div>` — which a client showing text as written would show as tags. Only a value
 * that starts with a tag is touched: one written as plain text may contain a `<` of its own, and
 * stripping it would eat the sentence. Markup with no text in it becomes null, which is what an
 * empty description is.
 *
 * There is no way down. The markup carried nothing the product asked for, and putting tags back
 * around text would be inventing them.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('chapters')
            ->select(['id', 'description'])
            ->orderBy('id')
            ->each(function (object $chapter): void {
                if (! is_string($chapter->description) || ! str_starts_with(ltrim($chapter->description), '<')) {
                    return;
                }

                $text = self::plainText($chapter->description);

                DB::table('chapters')
                    ->where('id', $chapter->id)
                    ->update(['description' => $text === '' ? null : $text]);
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
