<?php

declare(strict_types=1);

namespace App\Support\Modules;

use App\Support\Images\AcceptableLogo;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * "At most this many", in the product's words rather than the framework's.
 *
 * A rule object for the reason {@see AcceptableLogo} is one: Nova hands its own validator no
 * messages, so `max:10` would answer an operator with Laravel's English sentence about a field
 * called `videos`. The number and the sentence travel together here, which also means the two
 * cannot disagree — the message is built from the same limit the rule enforces.
 *
 * Counts are the one kind of rule the database cannot hold. A check constraint is about a row, and
 * "no more than ten of these" is about a set, so a form is genuinely the only place it can live —
 * which is why this is stated on both forms that build such a list rather than once underneath
 * them.
 */
final readonly class LimitedList implements ValidationRule
{
    public function __construct(
        private int $maximum,
        private string $message,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Anything that is not a list at all has already been refused by `array`, and saying so
        // twice would put two sentences under one field.
        if (! is_array($value)) {
            return;
        }

        if (count($value) > $this->maximum) {
            $fail($this->message);
        }
    }
}
