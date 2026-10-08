<?php

declare(strict_types=1);

namespace App\Support\Html;

use App\Nova\Repeatables\ContentBlockPreset;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * A prose column that holds markup, cleaned on its way into the row.
 *
 * Content is written in the panel's forms and through the API (rule 25), and the two doors do not
 * meet anywhere above the model: a contact's notes are written straight onto the column by a
 * Nova form, while the API writes them through an action, and a block's body reaches the row from
 * {@see ContentBlockPreset} without the field that drew it ever filling
 * anything. A cast is the one layer every one of those passes through, which is why the cleaning
 * lives here rather than on a field, in a request, or in an action. Nova's own Trix sanitizing is
 * turned off for the same reason: two allowlists are two rules, and the panel and the API would
 * refuse different markup the day one of them changed.
 *
 * The allowlist is Symfony's `allowSafeElements()`, which is the one Nova's Trix field itself
 * defaults to, so what an editor can produce is what survives. Nothing is cleaned on the way out:
 * the row already holds what was allowed, and sanitizing again on every read would be paying for
 * the same answer on every request.
 *
 * Markup that leaves nothing behind becomes null rather than an empty string, so a nullable column
 * reads back as absent rather than as blank prose. Where the column may not be null, the form
 * refuses it first and in Dutch ({@see NonEmptyHtml}) — rule 23's division, with the constraint
 * left holding only what goes around the form.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
final class SanitizedHtml implements CastsAttributes
{
    private static ?HtmlSanitizer $sanitizer = null;

    /**
     * The markup that survives, or null when none of it does.
     *
     * Public because the rule object asks the same question of a value the form has in hand,
     * before there is a model to cast it onto.
     */
    public static function clean(string $html): ?string
    {
        $clean = trim(self::sanitizer()->sanitize($html));

        return $clean === '' ? null : $clean;
    }

    /** @param  array<string, mixed>  $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * What the row keeps. Only a string or null is ever assigned to one of these columns, which is
     * what the generic above states and what the `string` rule on every one of these fields makes
     * true of both doors.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : self::clean($value);
    }

    private static function sanitizer(): HtmlSanitizer
    {
        return self::$sanitizer ??= new HtmlSanitizer((new HtmlSanitizerConfig)->allowSafeElements());
    }
}
