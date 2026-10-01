<?php

declare(strict_types=1);

namespace App\Support\Html;

use App\Support\Modules\NonEmptyList;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * "Something has to be left of this once it is cleaned", for a prose field that may not be blank.
 *
 * `required` is asked of what a caller sent, and {@see SanitizedHtml} is what the row ends up
 * holding: between the two sits markup that is entirely stripped — a lone script tag is the whole
 * of it — which passes `required` and leaves nothing. A column that may not be null would then be
 * refused by the database, and a check constraint is a 500 rather than a sentence somebody can
 * read (rule 23: the form refuses first and in Dutch, the constraint holds what goes around it).
 *
 * A rule object for the reason {@see NonEmptyList} is one: the sentence is the product's, and Nova
 * hands its own validator no messages.
 */
final readonly class NonEmptyHtml implements ValidationRule
{
    public function __construct(private string $message) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Absent, or not a string at all, is `required` and `string`'s to answer. Saying so here
        // as well would put two sentences under one field.
        if (! is_string($value)) {
            return;
        }

        if (SanitizedHtml::clean($value) === null) {
            $fail($this->message);
        }
    }
}
