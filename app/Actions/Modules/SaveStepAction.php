<?php

declare(strict_types=1);

namespace App\Actions\Modules;

use App\Models\Step;
use App\Models\User;
use App\Support\Access\ModuleAccess;
use App\Support\Modules\BlockDetails;
use Illuminate\Support\Facades\DB;

/** Creates a step at the end of its chapter with its blocks, or writes a new version of one. */
final readonly class SaveStepAction
{
    public function __construct(private SaveStepBlocksAction $blocks) {}

    /** @param  list<BlockDetails>  $blocks */
    public function execute(User $actor, Step $step, string $name, array $blocks): Step
    {
        ModuleAccess::ensureCanManageContent($actor);

        DB::transaction(function () use ($actor, $step, $name, $blocks): void {
            $step->name = trim($name);
            $step->save();

            $this->blocks->execute($actor, $step, $blocks);
        });

        return $step;
    }
}
