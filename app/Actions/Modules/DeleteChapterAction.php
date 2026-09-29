<?php

declare(strict_types=1);

namespace App\Actions\Modules;

use App\Models\Chapter;
use App\Models\User;
use App\Support\Access\ModuleAccess;

/** Removes a chapter for good, with its steps and their blocks. */
final readonly class DeleteChapterAction
{
    public function execute(User $actor, Chapter $chapter): void
    {
        ModuleAccess::ensureCanManageContent($actor);

        // Through the model, so the files of every block beneath are collected before the cascade.
        $chapter->delete();
    }
}
