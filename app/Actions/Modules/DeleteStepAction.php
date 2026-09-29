<?php

declare(strict_types=1);

namespace App\Actions\Modules;

use App\Models\Step;
use App\Models\User;
use App\Support\Access\ModuleAccess;

/** Removes a step for good, with its blocks. */
final readonly class DeleteStepAction
{
    public function execute(User $actor, Step $step): void
    {
        ModuleAccess::ensureCanManageContent($actor);

        // Through the model, so its blocks' files are collected before the cascade.
        $step->delete();
    }
}
