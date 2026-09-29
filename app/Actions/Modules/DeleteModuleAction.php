<?php

declare(strict_types=1);

namespace App\Actions\Modules;

use App\Models\Module;
use App\Models\User;
use App\Support\Access\ModuleAccess;

/**
 * Removes a module for good.
 *
 * There is no soft delete here and no undo — rule 24 — which is why the operator is shown a
 * sentence saying so before they confirm. What the sentence also promises is that the courses
 * inside the module survive it, and that is true because the pivot cascades and the courses do
 * not: nothing in this class has to remember it.
 *
 * A use case rather than a line in the Nova action, for the reason every other destructive
 * operation here is one: the permission question has exactly one answer wherever it is asked, and
 * deleting the platform's content is the platform's own job.
 */
final readonly class DeleteModuleAction
{
    public function execute(User $actor, Module $module): void
    {
        ModuleAccess::ensureCanManageContent($actor);

        // Deleted through the model rather than the query builder, so `DiscardsStoredFiles` runs:
        // the picture and every uploaded video beneath it are collected before the cascade takes
        // the rows that name them.
        $module->delete();
    }
}
