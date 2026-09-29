<?php

declare(strict_types=1);

namespace App\Actions\Modules;

use App\Models\ModuleCategory;
use App\Models\User;
use App\Support\Access\ModuleAccess;
use App\Support\Modules\CategoryNames;

/** Adds a category a module can be filed under. */
final readonly class CreateModuleCategoryAction
{
    public function __construct(private CategoryNames $names) {}

    public function execute(User $actor, string $name): ModuleCategory
    {
        ModuleAccess::ensureCanManageContent($actor);

        return $this->names->save(new ModuleCategory, $name);
    }
}
