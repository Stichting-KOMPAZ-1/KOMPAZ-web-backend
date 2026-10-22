<?php

declare(strict_types=1);

namespace App\Support\Modules;

/**
 * One option of a "Checkboxes" block: what it says, and the identifier a reader's ticks are kept
 * against.
 *
 * The identifier is what lets a client remember which boxes somebody ticked across an edit: a
 * label can be reworded and an option moved, and neither its text nor its place survives that. It
 * is null only on an option that was sent and not yet saved; {@see BlockCheckboxes::keepingIdsOf()}
 * gives every option one before a row holds it.
 */
final readonly class BlockCheckboxOption
{
    public function __construct(
        public ?string $id,
        public string $label,
    ) {}
}
