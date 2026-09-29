<?php

declare(strict_types=1);

namespace App\Support\Modules;

use App\Exceptions\ConflictException;
use App\Models\ModuleCategory;
use App\Support\Persistence\UniqueConstraint;
use Illuminate\Database\QueryException;

/**
 * Writes a category's name, refusing one another category already has.
 *
 * Shared by creating and renaming because the rule is the same and has an edge to it: the name is
 * compared folded, against the column the unique index is on (rule 10), and a request that loses
 * the race between that check and its write is answered with the same conflict the check gives,
 * from the index rather than from a 500 (rule 9). Renaming a category to its own name, in another
 * case, is not a clash with itself.
 */
final readonly class CategoryNames
{
    public function save(ModuleCategory $category, string $name): ModuleCategory
    {
        $category->applyName($name);

        $taken = ModuleCategory::query()
            ->where('normalized_name', $category->normalized_name)
            ->when($category->exists, fn ($others) => $others->whereKeyNot($category->getKey()))
            ->exists();

        if ($taken) {
            throw new ConflictException(ModuleMessages::CATEGORY_NAME_TAKEN);
        }

        try {
            $category->save();
        } catch (QueryException $exception) {
            if (UniqueConstraint::wasViolated($exception)) {
                throw new ConflictException(ModuleMessages::CATEGORY_NAME_TAKEN);
            }

            throw $exception;
        }

        return $category;
    }
}
