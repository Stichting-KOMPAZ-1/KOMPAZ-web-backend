<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A contact's "Reden voor contact" and "Beschikbaarheid" are plain text, as a chapter's
 * description became four days before them.
 *
 * Cards saved while both were written in the panel's rich-text editor hold the editor's markup —
 * `<div>…<br>…</div>` — which a client showing text as written would show as tags. Only a value
 * that starts with a tag is touched: one written as plain text may contain a `<` of its own, and
 * stripping it would eat the sentence. Markup with no text in it becomes null, which is what an
 * empty note is.
 *
 * There is no way down. The markup carried nothing the product asked for, and putting tags back
 * around text would be inventing them.
 */
return new class extends Migration
{
    private const array COLUMNS = ['reason', 'availability'];

    public function up(): void
    {
        DB::table('module_contacts')
            ->select(['id', ...self::COLUMNS])
            ->orderBy('id')
            ->each(function (object $contact): void {
                $changes = [];

                foreach (self::COLUMNS as $column) {
                    $value = $contact->{$column};

                    if (! is_string($value) || ! str_starts_with(ltrim($value), '<')) {
                        continue;
                    }

                    $text = self::plainText($value);
                    $changes[$column] = $text === '' ? null : $text;
                }

                if ($changes !== []) {
                    DB::table('module_contacts')->where('id', $contact->id)->update($changes);
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
