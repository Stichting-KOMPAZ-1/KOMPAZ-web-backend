<?php

declare(strict_types=1);

namespace App\Actions\Modules;

use App\Exceptions\NotFoundException;
use App\Models\User;
use App\Support\Access\ModuleAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Puts a course's chapters, or a chapter's steps, in the order an operator dragged them into.
 *
 * The order is the order the front end reads, so it is a product fact and not a panel detail.
 *
 * **Only the dragged rows move, and only into places they already held.** The panel sends the
 * rows on the page it shows, which for a long list is a slice of it, so the others stay where they
 * were. Then the whole list is renumbered from nought: two rows that ever shared a position — two
 * chapters created in the same instant, say — stop sharing it, and the next drag is unambiguous.
 */
final readonly class ReorderContentAction
{
    /**
     * @param  HasMany<covariant Model, covariant Model>  $children  the list being reordered, under its parent
     * @param  list<string>  $orderedIds  some of those rows, in their new order
     */
    public function execute(User $actor, HasMany $children, array $orderedIds): void
    {
        ModuleAccess::ensureCanManageContent($actor);

        DB::transaction(function () use ($children, $orderedIds): void {
            $rows = $children->orderBy('id')->lockForUpdate()->get()
                ->keyBy(fn (Model $row): string => (string) $row->getKey());

            $order = $rows->keys()->all();

            // Every key the browser sent has to be one of this parent's rows, once. Otherwise one
            // course's drag could renumber another course's chapters.
            if (count(array_unique($orderedIds)) !== count($orderedIds) || array_diff($orderedIds, $order) !== []) {
                throw new NotFoundException('Deze onderdelen horen niet bij deze lijst.');
            }

            $slots = array_keys(array_intersect($order, $orderedIds));

            foreach ($slots as $index => $slot) {
                $order[$slot] = $orderedIds[$index];
            }

            foreach (array_values($order) as $position => $key) {
                $row = $rows->get($key);

                if ($row !== null && $row->getAttribute('position') !== $position) {
                    $row->setAttribute('position', $position);
                    $row->save();
                }
            }
        });
    }
}
