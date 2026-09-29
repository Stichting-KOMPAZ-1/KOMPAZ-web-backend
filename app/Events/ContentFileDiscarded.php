<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A stored content file is no longer pointed at by anything and its bytes should go.
 *
 * The content counterpart of {@see OrganizationLogoDiscarded}, kept separate for the reason the
 * two served responses are: they are raised by different things and a listener that handled both
 * would have to ask which. Carries the key as a value because by the time this runs the row that
 * knew it is gone.
 *
 * Raised from model events rather than from an action. Content is written through Nova's own forms
 * — see rule 18 — so there is no single use case to put this in, and a delete performed from the
 * panel, from a test or from tinker all have to let go of the same bytes.
 */
final readonly class ContentFileDiscarded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public string $storageKey) {}
}
