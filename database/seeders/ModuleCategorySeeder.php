<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ModuleCategory;
use Illuminate\Database\Seeder;

/**
 * Plants the first categories a module can be filed under, on an installation that has none.
 *
 * **Only into an empty table.** The seeder runs on every deploy, and since KOM-51 the platform
 * creates, renames and deletes categories itself. Planting every missing default each time would
 * bring back a category somebody deleted on purpose, and plant the old name again beside one they
 * renamed. So this is a first install's starting point and nothing more: once there is any
 * category at all, the list is the platform's.
 *
 * Matched on the folded name all the same, so a default is never planted twice.
 */
final class ModuleCategorySeeder extends Seeder
{
    /** @var list<string> */
    private const array CATEGORIES = [
        'Revalidatie',
        'Medicatie',
    ];

    public function run(): void
    {
        if (ModuleCategory::query()->exists()) {
            return;
        }

        foreach (self::CATEGORIES as $name) {
            $existing = ModuleCategory::query()
                ->where('normalized_name', ModuleCategory::normalize($name))
                ->first();

            if ($existing !== null) {
                continue;
            }

            $category = new ModuleCategory;
            $category->applyName($name);
            $category->save();
        }
    }
}
