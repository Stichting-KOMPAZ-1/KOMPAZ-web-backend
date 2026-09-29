<?php

declare(strict_types=1);

namespace App\Support\Modules;

use App\Models\ModuleCategory;
use App\Support\Organizations\OrganizationName;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * What a category's name has to be before anything tries to store it: present, and short enough.
 *
 * A rule object for the reason {@see OrganizationName} is one: Nova
 * hands an action's validator no messages, and the panel's dialog and the API have to refuse in
 * the same sentence. Whether the name is already taken is the use case's to answer, because only
 * the table knows — folded, as {@see ModuleCategory::normalize()} folds it.
 *
 * Measured after trimming, because {@see ModuleCategory::applyName()} trims before storing.
 */
final class CategoryName implements ValidationRule
{
    /** Asked of an empty value too, which is the case "vul een naam in" is about. */
    public bool $implicit = true;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            $fail(ModuleMessages::CATEGORY_NAME_REQUIRED);

            return;
        }

        if (mb_strlen(trim($value)) > ModuleCategory::MAXIMUM_NAME_LENGTH) {
            $fail(ModuleMessages::categoryNameTooLong(ModuleCategory::MAXIMUM_NAME_LENGTH));
        }
    }
}
