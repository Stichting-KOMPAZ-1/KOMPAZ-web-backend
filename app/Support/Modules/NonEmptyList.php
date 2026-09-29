<?php

declare(strict_types=1);

namespace App\Support\Modules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * "At least one of these", in the product's words rather than the framework's.
 *
 * The other end of {@see LimitedList}, and a rule object for the same reason. It is implicit,
 * because a repeater with no rows sends nothing at all under its name, and a rule that is not
 * implicit is never asked about a value that is missing.
 */
final class NonEmptyList implements ValidationRule
{
    /** Asked even when the field is absent, which is exactly how an empty list arrives. */
    public bool $implicit = true;

    public function __construct(private readonly string $message) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value) || $value === []) {
            $fail($this->message);
        }
    }
}
