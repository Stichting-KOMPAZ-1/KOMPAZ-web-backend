<?php

declare(strict_types=1);

namespace App\Actions\Modules;

use App\Models\ModuleCategory;
use App\Models\User;
use App\Support\Access\ModuleAccess;
use App\Support\Modules\CategoryNames;

/** Renames a category. The modules filed under it follow, since they point at the row. */
final readonly class RenameModuleCategoryAction
{
    public function __construct(private CategoryNames $names) {}

    public function execute(User $actor, ModuleCategory $category, string $name): ModuleCategory
    {
        ModuleAccess::ensureCanManageContent($actor);

        return $this->names->save($category, $name);
    }
}
