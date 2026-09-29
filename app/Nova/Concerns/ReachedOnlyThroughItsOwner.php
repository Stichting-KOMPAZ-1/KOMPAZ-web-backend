<?php

declare(strict_types=1);

namespace App\Nova\Concerns;

use Illuminate\Http\Request;

/**
 * A resource that exists only because a repeater addresses its rows through one.
 *
 * Its rows are written inside the form of whatever owns them, where the owner's own checks apply.
 * Addressed on their own they have none — a contact reached by its key carries no answer to "whose
 * is this?" — so every question Nova can ask of one directly is answered no, reading and deleting
 * included, and not just the two that put it in the menu.
 */
trait ReachedOnlyThroughItsOwner
{
    public static function authorizedToViewAny(Request $request): bool
    {
        return false;
    }

    public function authorizedToView(Request $request): bool
    {
        return false;
    }

    public static function authorizedToCreate(Request $request): bool
    {
        return false;
    }

    public function authorizedToUpdate(Request $request): bool
    {
        return false;
    }

    public function authorizedToDelete(Request $request): bool
    {
        return false;
    }
}
