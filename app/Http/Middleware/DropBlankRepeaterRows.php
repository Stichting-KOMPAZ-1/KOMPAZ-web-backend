<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * Leaves out a row somebody added to a panel form with "+" and then left empty.
 *
 * KOM-73: an operator adds a picture block, changes their mind, and saves without choosing a
 * picture. Refusing the whole form over a row they no longer want reads as the panel being in the
 * way, so a row with nothing at all in it is treated as never added. A row with *anything* in it is
 * still validated as before — half a contact card is a mistake worth pointing out, an untouched one
 * is not — and so is a row that already exists: it carries its key in a hidden field, which is
 * something in it, and an existing picture block sends back no picture, so "empty" would be the
 * wrong reading of it.
 *
 * Here rather than on each field, because Nova validates a repeater's rows from the request before
 * any field or preset sees them, and the panel has five such lists (a module's videos and links, a
 * copy's videos, links and contacts, a part's blocks). Removed rows keep the positions of the rows
 * around them: an uploaded file is addressed by its row's index, so renumbering would hand one row's
 * picture to the next. Only the panel's API passes through this — a client of `/api` sends the list
 * it means, and is told when a row in it is incomplete.
 */
final readonly class DropBlankRepeaterRows
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethodSafe()) {
            foreach ($request->input() as $attribute => $value) {
                if (is_string($attribute) && self::isRepeater($value)) {
                    $request->merge([$attribute => self::withoutBlankRows($request, $attribute, $value)]);
                }
            }
        }

        return $next($request);
    }

    /** Nova's shape for a repeater's rows: a list of `{type, fields}`, every row naming its type. */
    private static function isRepeater(mixed $value): bool
    {
        if (! is_array($value) || $value === []) {
            return false;
        }

        foreach ($value as $row) {
            // `fields` itself may be missing: a multipart form sends nothing for a row whose every
            // field is empty.
            if (! is_array($row) || ! is_string($row['type'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<array-key, array<string, mixed>>  $rows
     * @return array<array-key, array<string, mixed>>
     */
    private static function withoutBlankRows(Request $request, string $attribute, array $rows): array
    {
        foreach ($rows as $index => $row) {
            $fields = is_array($row['fields'] ?? null) ? $row['fields'] : [];

            if (self::isBlank($fields) && ! self::carriesFile($request, "{$attribute}.{$index}.fields")) {
                unset($rows[$index]);
            }
        }

        return $rows;
    }

    /** @param  array<array-key, mixed>  $fields */
    private static function isBlank(array $fields): bool
    {
        foreach ($fields as $value) {
            if (self::hasText($value)) {
                return false;
            }
        }

        return true;
    }

    /** Whether a value says anything, counting markup with no text in it — an empty editor — as nothing. */
    private static function hasText(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (self::hasText($item)) {
                    return true;
                }
            }

            return false;
        }

        if (! is_string($value)) {
            return $value !== null && $value !== false;
        }

        $text = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim($text, " \t\n\r\0\x0B\u{00A0}") !== '' && ! in_array(trim($value), ['null', '[]', '{}'], true);
    }

    private static function carriesFile(Request $request, string $fields): bool
    {
        $files = $request->file($fields);

        if ($files instanceof UploadedFile) {
            return true;
        }

        return is_array($files) && $files !== [];
    }
}
