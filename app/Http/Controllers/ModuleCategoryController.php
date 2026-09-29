<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\ModuleCategoryResource;
use App\Models\ModuleCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The categories a module is filed under, for a form's picker and a listing's filter.
 *
 * Read-only: adding a category is a seeder change in this phase (KOM-51 is on the backlog). Not
 * tenant data — every organization files modules under the same few — so anybody signed in may
 * read them.
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
}
