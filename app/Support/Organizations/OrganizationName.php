<?php

declare(strict_types=1);

namespace App\Support\Organizations;

use App\Models\Organization;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * What an organization's name has to be before anything tries to store it: present, and short
 * enough for the column that carries it.
 *
 * A rule object because the panel cannot use the mechanism the API uses. A form request states its
 * wording in `messages()`, keyed by rule; Nova validates an action's fields itself and hands the
 * validator no messages at all, so a plain `required` there answers in the framework's words
 * rather than in the product's. Stating the rule as an object is what lets the panel say the same
 * sentence the API says.
 *
 * Measured after trimming, because {@see Organization::applyName()} trims before storing: a name
 * that fits once the spaces are gone does fit.
 */
final class OrganizationName implements ValidationRule
{
    /**
     * Runs even when the field arrived empty, which is the case it mainly exists to answer. A rule
     * object is skipped on an empty value unless it says otherwise, and "you left this blank" is
     * precisely a judgement about an empty value.
     */
    public bool $implicit = true;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            $fail(OrganizationMessages::NAME_REQUIRED);

            return;
        }

        if (mb_strlen(trim($value)) > Organization::MAXIMUM_NAME_LENGTH) {
            $fail(OrganizationMessages::nameTooLong(Organization::MAXIMUM_NAME_LENGTH));
        }
    }
}
