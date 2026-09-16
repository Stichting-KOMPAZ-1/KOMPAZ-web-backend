<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A stored logo is no longer pointed at by anything and its file should go.
 *
 * Raised whenever a row stops naming a key — a replacement, a deleted logo, a deleted organization,
 * which has to ask for the key before the cascade takes the row — and handled after the commit,
 * because the file disk is outside the database and its transaction. Carries the key as a value
 * because by the time this runs the row that knew it may be gone.
 */
final readonly class OrganizationLogoDiscarded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public string $storageKey) {}
}
