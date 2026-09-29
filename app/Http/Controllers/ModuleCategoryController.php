<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Modules\CreateModuleCategoryAction;
use App\Actions\Modules\DeleteModuleCategoryAction;
use App\Actions\Modules\RenameModuleCategoryAction;
use App\Http\Requests\SaveModuleCategoryRequest;
use App\Http\Resources\ModuleCategoryResource;
use App\Models\ModuleCategory;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The categories a module is filed under (KOM-51).
 *
 * Anybody signed in may read them — a listing's filter needs them, and they are not tenant data.
 * Writing them is the platform's, and every action asks again.
 */
final readonly class ModuleCategoryController
{
    /** Returns every category, by name, under `items` like every other list here. */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'items' => ModuleCategoryResource::collection(ModuleCategory::query()->orderBy('name')->get())->toArray($request),
        ]);
    }

    /** Adds a category. A name another one has, in any case, is a conflict. */
    public function store(SaveModuleCategoryRequest $request, CreateModuleCategoryAction $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return ModuleCategoryResource::make($action->execute($actor, $request->name()))
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }

    /** Renames a category. The modules filed under it follow. */
    public function update(SaveModuleCategoryRequest $request, ModuleCategory $category, RenameModuleCategoryAction $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return ModuleCategoryResource::make($action->execute($actor, $category, $request->name()))->response();
    }

    /** Deletes a category no module is filed under. One still in use is a conflict. */
    public function destroy(Request $request, ModuleCategory $category, DeleteModuleCategoryAction $action): Response
    {
        /** @var User $actor */
        $actor = $request->user();

        $action->execute($actor, $category);

        return response()->noContent();
    }
}
