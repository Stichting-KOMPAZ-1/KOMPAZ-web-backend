<?php

declare(strict_types=1);

namespace App\Actions\Modules;

use App\Models\ELearning;
use App\Models\Module;
use App\Models\ModuleLink;
use App\Models\User;
use App\Support\Access\ModuleAccess;
use App\Support\Modules\LinkDetails;
use App\Support\Modules\ModuleDetails;
use App\Support\Modules\OrderedRows;
use Illuminate\Support\Facades\DB;

/**
 * Creates a module, or writes a new version of one, from the API.
 *
 * The panel writes a module through its own form — rule 18's carve-out — and this is the same
 * module written from the other door. What has to hold whoever writes it is not restated here but
 * asked of the one place that holds it: who gets the module is {@see SyncModuleActivationsAction},
 * which keeps an organization's date; the videos are {@see SaveModuleVideosAction}, which claims
 * an upload and lets go of a removed video's file.
 *
 * A list the details leave null is left as it is.
 */
final readonly class SaveModuleAction
{
    public function __construct(
        private SyncModuleActivationsAction $activations,
        private SaveModuleVideosAction $videos,
    ) {}

    public function execute(User $actor, Module $module, ModuleDetails $details): Module
    {
        ModuleAccess::ensureCanManageContent($actor);

        DB::transaction(function () use ($actor, $module, $details): void {
            $module->fill([
                'name' => trim($details->name),
                'category_id' => $details->categoryId,
                'description' => $details->description,
                'source_attribution' => $details->sourceAttribution,
                'status' => $details->status,
            ]);
            $module->save();

            if ($details->eLearningIds !== null) {
                // Narrowed to courses that exist, so a stale key is dropped rather than refused.
                $module->eLearnings()->sync(
                    ELearning::query()->whereKey($details->eLearningIds)->pluck('id')->all(),
                );
            }

            if ($details->videos !== null) {
                $this->videos->execute($actor, $module, $details->videos);
            }

            if ($details->links !== null) {
                OrderedRows::write(
                    $module->links(),
                    $details->links,
                    static fn (LinkDetails $link): ?string => $link->id,
                    static function (ModuleLink $row, LinkDetails $link): void {
                        $row->title = trim($link->title);
                        $row->url = trim($link->url);
                    },
                );
            }

            if ($details->organizationIds !== null) {
                $this->activations->execute($actor, $module, $details->organizationIds);
            }
        });

        return $module;
    }
}
