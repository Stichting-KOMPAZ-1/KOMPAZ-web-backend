<?php

declare(strict_types=1);

namespace App\Support\Links;

use App\Support\Modules\ModuleMessages;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * "This is somewhere a browser can go", asked of the address the row would actually keep.
 *
 * Laravel's `url` rule refuses `www.voorbeeld.nl`, which {@see WebAddress} would store perfectly
 * well as `https://www.voorbeeld.nl` — so the question is put to the completed address instead.
 * Only `http` and `https`: a link or a video on a module is a web page, and an operator who typed
 * `javascript:` or `mailto:` has not written one. A rule object rather than a rule string, because
 * the sentence is the product's and Nova hands its own validator no messages (rule 18).
 */
final readonly class AcceptableWebAddress implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Absent, blank, or not a string at all is `required`, `nullable` and `string`'s to answer.
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $address = WebAddress::complete($value);

        if ($address === null || ! self::isWebAddress($address)) {
            $fail(ModuleMessages::INVALID_WEB_ADDRESS);

            return;
        }

        if (mb_strlen($address) > WebAddress::MAXIMUM_LENGTH) {
            $fail(ModuleMessages::webAddressTooLong());
        }
    }

    private static function isWebAddress(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($address);

        if ($parts === false) {
            return false;
        }

        // A login in the address is never a link somebody meant to publish — and it is what
        // `mailto:iemand@voorbeeld.nl` turns into once it is given a scheme: `mailto` as a user
        // name on the host `voorbeeld.nl`.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        // A host with no dot is a typo far more often than an intranet name: "https://voorbeeld"
        // passes the filter and leads nowhere.
        return in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && str_contains($parts['host'] ?? '', '.');
    }
}
