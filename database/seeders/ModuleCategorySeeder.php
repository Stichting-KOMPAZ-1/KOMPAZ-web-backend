<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ModuleCategory;
use Illuminate\Database\Seeder;

/**
 * Plants the categories a module can be filed under.
 *
 * Here rather than behind a form because nothing in this phase creates one: the module form picks
 * from what exists, and a category that cannot be created has to arrive somehow. Idempotent, like
 * everything else that runs on every deploy — matched on the folded name, so a category is not
 * planted twice because somebody once typed it differently.
 *
 * Only the two the wireframes name are here. Adding to this list is a deploy, which is the honest
 * cost of the product's decision to leave creating them out of this phase.
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
