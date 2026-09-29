<?php

declare(strict_types=1);

namespace App\Actions\Modules;

use App\Models\Module;
use App\Models\User;
use App\Support\Access\ModuleAccess;

/** Leaves a module with no picture, which it may have: some modules have none. */
final readonly class RemoveModuleImageAction
{
    public function execute(User $actor, Module $module): Module
    {
        ModuleAccess::ensureCanManageContent($actor);

        $module->clearImage();
        $module->save();

        return $module;
    }
}
