<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Modules\SaveModuleActivationAction;
use App\Exceptions\NotFoundException;
use App\Http\Requests\SaveModuleActivationRequest;
use App\Http\Resources\ModuleActivationResource;
use App\Http\Resources\ModuleReachResource;
use App\Models\Module;
use App\Models\ModuleActivation;
use App\Models\Organization;
use App\Models\User;
use App\Support\Access\ModuleAccess;
use App\Support\Access\OrganizationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A module at one organization: which organizations have it, and what each one added to its copy.
 *
 * Two different questions. Who has the module is the platform's, and is written through the
 * module itself (`organizationIds`). What an organization added is that organization's — its own
 * administrators and the platform may write it — so it is asked of `OrganizationAccess` with the
 * organization in the address, and never reaches anybody else's copy (rule 22).
 */
final readonly class ModuleActivationController
{
    /** Returns the organizations a module is switched on for. The platform's question alone. */
    public function index(Request $request, Module $module): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        ModuleAccess::ensureCanManageContent($actor);

        $activations = $module->activations()
            ->with('organization')
            ->withCount('contacts')
            ->orderByDesc('activated_at')
            ->get();

        return response()->json([
            'items' => ModuleReachResource::collection($activations)->toArray($request),
        ]);
    }

    /** Returns what one organization added to its copy of a module, for the form that edits it. */
    public function show(Request $request, Module $module, Organization $organization): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        OrganizationAccess::ensureCanManage($actor, (string) $organization->getKey());

        return self::detail(self::activation($module, $organization))->response();
    }

    /** Writes what one organization adds to its copy. A list left out is left as it is. */
    public function update(
        SaveModuleActivationRequest $request,
        Module $module,
        Organization $organization,
        SaveModuleActivationAction $action,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();

        $activation = $action->execute($actor, self::activation($module, $organization), $request->details());

        return self::detail($activation)->response();
    }

    /**
     * The organization's copy, or not found.
     *
     * Not found rather than forbidden, like every content refusal: whether a module was given to
     * an organization is not something to learn from a refusal. Asked after the tenancy check, so
     * that answer is only ever given to somebody who manages the organization anyway.
     */
    private static function activation(Module $module, Organization $organization): ModuleActivation
    {
        $activation = $module->activationFor((string) $organization->getKey());

        if ($activation === null) {
            throw new NotFoundException('Deze module is niet actief bij deze organisatie.');
        }

        return $activation;
    }

    private static function detail(ModuleActivation $activation): ModuleActivationResource
    {
        return ModuleActivationResource::make($activation->load(['videos', 'links', 'contacts']));
    }
}
