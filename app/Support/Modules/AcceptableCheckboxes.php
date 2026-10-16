<?php

declare(strict_types=1);

namespace App\Support\Modules;

use App\Actions\Modules\SaveStepBlocksAction;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Whether what was sent for a "Checkboxes" block is a set of options this platform keeps (KOM-107).
 *
 * At most twenty options, none longer than a label may be. A blank one among them is not refused —
 * it is dropped, as an untouched "+ optie" row is — but a set that is nothing *but* blanks has no
 * options at all and is refused as such. Asked of the panel's field, which sends JSON, and of the
 * API's `checkboxes`, which is an array, so it reads both; a rule object for the reason every rule
 * here that answers in the product's words is one (rule 18).
 *
 * Absent is not this rule's to refuse: whether a checkbox block must have options is the use case's
 * question, because a block's kind decides it ({@see SaveStepBlocksAction}).
 */
final readonly class AcceptableCheckboxes implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $options = BlockCheckboxes::decode($value);

        if ($options === null) {
            $fail(ModuleMessages::CHECKBOXES_ARE_MALFORMED);

            return;
        }

        if (count($options) > BlockCheckboxes::MAXIMUM_OPTIONS) {
            $fail(ModuleMessages::checkboxesTooMany());

            return;
        }

        foreach ($options as $label) {
            // Null counts as blank, not as malformed: `ConvertEmptyStringsToNull` turns an option
            // left empty into one before this ever sees it, and a blank option is dropped rather
            // than refused. Refusing it here would refuse the very thing the panel's "+ optie"
            // produces.
            if ($label !== null && ! is_scalar($label)) {
                $fail(ModuleMessages::CHECKBOXES_ARE_MALFORMED);

                return;
            }

            if (mb_strlen(trim((string) $label)) > BlockCheckboxes::MAXIMUM_LABEL_LENGTH) {
                $fail(ModuleMessages::checkboxLabelTooLong());

                return;
            }
        }

        // Counted after the blanks are dropped, because that is what would be stored: a set typed
        // as three empty rows is a block with no options, not a block with three.
        if (BlockCheckboxes::fromInput($options) === null) {
            $fail(ModuleMessages::CHECKBOXES_NEED_AN_OPTION);
        }
    }
}
