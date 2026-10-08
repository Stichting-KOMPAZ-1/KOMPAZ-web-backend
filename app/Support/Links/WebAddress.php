<?php

declare(strict_types=1);

namespace App\Support\Links;

use App\Support\Html\SanitizedHtml;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A column holding a web address, completed on its way into the row.
 *
 * Somebody filling in a link types `www.voorbeeld.nl` far more often than
 * `https://www.voorbeeld.nl`, and the product asked for that to simply work (KOM-73) rather than be
 * refused as "not a URL". An address without a scheme is given `https://`, which is what every
 * browser assumes of one too. A cast for the reason {@see SanitizedHtml} is one:
 * a link reaches its row from a Nova form, from Nova's repeater preset and from an action behind
 * the API, and the model is the one layer all three pass through.
 *
 * What is *acceptable* is {@see AcceptableWebAddress}'s to say, of the address this would store.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
final class WebAddress implements CastsAttributes
{
    /** The longest address a column holds, scheme included. */
    public const int MAXIMUM_LENGTH = 2048;

    /**
     * The address as it would be stored: trimmed, given `https://` when it names no scheme, and
     * null when nothing was typed.
     */
    public static function complete(string $address): ?string
    {
        $address = trim($address);

        if ($address === '') {
            return null;
        }

        return preg_match('~^[a-z][a-z0-9+.-]*://~i', $address) === 1 ? $address : 'https://'.$address;
    }

    /** @param  array<string, mixed>  $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return is_string($value) ? $value : null;
    }

    /** @param  array<string, mixed>  $attributes */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return is_string($value) ? self::complete($value) : null;
    }
}
