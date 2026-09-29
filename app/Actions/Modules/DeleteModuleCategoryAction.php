<?php

declare(strict_types=1);

namespace App\Actions\Modules;

use App\Exceptions\ConflictException;
use App\Models\ModuleCategory;
use App\Models\User;
use App\Support\Access\ModuleAccess;
use App\Support\Modules\ModuleMessages;
use Illuminate\Support\Facades\DB;

/**
 * Removes a category that no module is filed under any more.
 *
 * One still in use is refused rather than taking its modules with it — the foreign key restricts
 * it too, and this says why in words an operator can act on: move the modules first. The row is
 * locked before the count, so a module filed under it a moment later waits for this to finish
 * rather than slipping in between the count and the delete and turning a refusal into a 500.
 */
final readonly class DeleteModuleCategoryAction
{
    public function execute(User $actor, ModuleCategory $category): void
    {
        ModuleAccess::ensureCanManageContent($actor);

        DB::transaction(function () use ($category): void {
            ModuleCategory::query()->whereKey($category->getKey())->lockForUpdate()->first();

            $inUse = $category->modules()->count();

            if ($inUse > 0) {
                throw new ConflictException(ModuleMessages::categoryInUse($inUse));
            }

            $category->delete();
        });
    }
}
