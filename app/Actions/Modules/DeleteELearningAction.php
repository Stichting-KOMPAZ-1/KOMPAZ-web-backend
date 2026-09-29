<?php

declare(strict_types=1);

namespace App\Actions\Modules;

use App\Models\ELearning;
use App\Models\User;
use App\Support\Access\ModuleAccess;

/**
 * Removes a course for good, with its chapters, its steps and their blocks.
 *
 * The modules that showed it survive, which is what the confirmation an operator reads promises —
 * and it is true because the pivot cascades and the modules do not, so nothing in this class has
 * to remember it.
 */
final readonly class DeleteELearningAction
{
    public function execute(User $actor, ELearning $eLearning): void
    {
        ModuleAccess::ensureCanManageContent($actor);

        // Through the model rather than the query builder, so `DiscardsStoredFiles` runs: the
        // picture and every block file three cascades below are collected before the rows that
        // name them go.
        $eLearning->delete();
    }
}
