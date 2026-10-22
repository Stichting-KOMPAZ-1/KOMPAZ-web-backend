<?php

declare(strict_types=1);

namespace App\Support\Modules;

use App\Actions\Modules\SaveStepBlocksAction;
use App\Models\CarouselSlide;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Whether what was sent for a "Carrousel" block is a list of slides this platform keeps (KOM-100).
 *
 * Shape and count only: a list of slides, at most as many as the product allows. What each slide
 * must hold — a title, a picture — is asked by {@see SaveStepBlocksAction} for both doors, because
 * only the block's own rows know whether a slide sent without a picture already has one.
 *
 * Reads a JSON string as readily as an array, because the panel's editor sends one: Nova's repeater
 * cannot nest a repeating list inside a block's row, so the component that stands in for one sends
 * a string.
 */
final readonly class AcceptableSlides implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $slides = BlockCheckboxes::decode($value);

        if ($slides === null) {
            $fail(ModuleMessages::SLIDES_ARE_MALFORMED);

            return;
        }

        if (count($slides) > CarouselSlide::MAXIMUM_PER_CAROUSEL) {
            $fail(ModuleMessages::tooManySlides());

            return;
        }

        foreach ($slides as $slide) {
            if (! is_array($slide)) {
                $fail(ModuleMessages::SLIDES_ARE_MALFORMED);

                return;
            }
        }
    }
}
