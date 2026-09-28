<?php

declare(strict_types=1);

namespace App\Actions\Modules;

use App\Events\ContentFileDiscarded;
use App\Models\Module;
use App\Models\ModuleActivation;
use App\Models\ModuleVideo;
use App\Models\Organization;
use App\Models\User;
use App\Support\Access\ModuleAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Puts a module in front of a set of organizations, and takes it away from the rest.
 *
 * An action rather than a Nova form field, even though rule 18 lets the content resources write
 * their own columns, because this one carries a rule a form would get wrong: **an organization
 * that already had the module keeps the date it got it**. The organization administrator's table
 * is ordered by `activated_at` so a module they still have to fill in floats to the top, and a
 * plain `sync()` — detach everything, re-attach everything — would reset every one of those dates
 * each time a platform administrator saved the form for an unrelated reason.
 *
 * Switching a module off does take that organization's own additions with it. That is the cascade
 * on the activation row and it is deliberate: their videos, their links and their contact card
 * were about a module they no longer have, and keeping them would resurrect somebody's old phone
 * number the day the module came back.
 */
final readonly class SyncModuleActivationsAction
{
    /**
     * @param  list<string>  $organizationIds  the organizations that should have it afterwards
     */
    public function execute(User $actor, Module $module, array $organizationIds): void
    {
        // Who a module reaches is the platform's call, never a tenant's. Asked here rather than
        // left to the panel, so the rule holds for anything else that ever calls this.
        ModuleAccess::ensureCanManageContent($actor);

        // Narrowed to organizations that exist, so a stale identifier in a form somebody left open
        // is dropped rather than written as a row pointing at nothing.
        /** @var list<string> $wanted */
        $wanted = Organization::query()
            ->whereKey($organizationIds)
            ->pluck('id')
            ->all();

        DB::transaction(function () use ($module, $wanted): void {
            $losing = ModuleActivation::query()
                ->where('module_id', $module->getKey())
                ->whereNotIn('organization_id', $wanted)
                ->pluck('id')
                ->all();

            if ($losing !== []) {
                $this->releaseActivations($losing);
            }

            $existing = ModuleActivation::query()
                ->where('module_id', $module->getKey())
                ->pluck('organization_id')
                ->all();

            foreach (array_diff($wanted, $existing) as $organizationId) {
                // Only the ones that are new. Rows already there are left exactly as they are,
                // which is the whole point of not using sync().
                ModuleActivation::query()->create([
                    'module_id' => $module->getKey(),
                    'organization_id' => $organizationId,
                    'activated_at' => Carbon::now(),
                ]);
            }
        });
    }

    /**
     * Removes activations, having first asked what files go with them.
     *
     * The keys are collected before the delete for the reason rule 13 gives: the cascade takes the
     * organization's videos without Eloquent seeing a single one of them, and afterwards nothing
     * knows where their bytes were. The events are held until the transaction commits, so a
     * rollback does not delete files for rows that are still there.
     *
     * @param  list<string>  $activationIds
     */
    private function releaseActivations(array $activationIds): void
    {
        $keys = ModuleVideo::query()
            ->whereIn('module_activation_id', $activationIds)
            ->whereNotNull('file_storage_key')
            ->pluck('file_storage_key')
            ->all();

        ModuleActivation::query()->whereKey($activationIds)->delete();

        foreach ($keys as $key) {
            if (is_string($key)) {
                ContentFileDiscarded::dispatch($key);
            }
        }
    }
}
