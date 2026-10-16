<?php

declare(strict_types=1);

namespace App\Support\Modules;

use App\Actions\Modules\SaveStepBlocksAction;
use App\Models\ContentBlock;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Whether what was sent for a "Stap" block is a list of instructions this platform keeps (KOM-99).
 *
 * Shape and count only: a list, of at most as many instructions as the product allows. What each
 * instruction must contain is asked per instruction — by the API's own `instructions.*.body` rule,
 * and by {@see SaveStepBlocksAction} for both doors, which is where a block's kind decides whether
 * it may be without them at all.
 *
 * Reads a JSON string as readily as an array, because the panel's editor sends one: Nova's repeater
 * cannot nest a repeating list inside a block's row, so the component that stands in for one sends
 * a string. A plain `array` rule refused exactly that.
 */
final readonly class AcceptableInstructions implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $instructions = BlockCheckboxes::decode($value);

        if ($instructions === null) {
            $fail(ModuleMessages::INSTRUCTIONS_ARE_MALFORMED);

            return;
        }

        if (count($instructions) > ContentBlock::MAXIMUM_INSTRUCTIONS) {
            $fail(ModuleMessages::tooManyInstructions());

            return;
        }

        foreach ($instructions as $instruction) {
            if (! is_array($instruction)) {
                $fail(ModuleMessages::INSTRUCTIONS_ARE_MALFORMED);

                return;
            }
        }
    }
}
