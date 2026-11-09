<?php

declare(strict_types=1);

namespace App\Support\Modules;

/**
 * A picture's alt text as a request states it, when it states one at all.
 *
 * Wrapped so that "no alt text" and "not said" stay apart, as a list left out of a body does: a
 * client renaming a course must not clear the description of its picture by leaving the key out.
 */
final readonly class ImageAlt
{
    public function __construct(public ?string $text) {}

    public static function fromInput(mixed $value): self
    {
        return new self(is_string($value) && trim($value) !== '' ? trim($value) : null);
    }
}
