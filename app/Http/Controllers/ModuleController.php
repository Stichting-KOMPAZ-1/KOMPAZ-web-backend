<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\NotFoundException;
use App\Http\Requests\IndexModulesRequest;
use App\Http\Resources\ModuleResource;
use App\Http\Resources\ModuleSummaryResource;
use App\Http\Resources\PaginatedCollection;
use App\Models\Module;
use App\Models\ModuleVideo;
use App\Models\User;
use App\Support\Access\ModuleAccess;
use App\Support\Files\ServedFile;
use App\Support\Pagination\PaginatedList;
use App\Support\Search\SearchPattern;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The modules an organization has been given, as its people read them.
 *
 * Read-only. Modules are written in the operator's panel and nowhere else, which is what the
 * tickets asked for — a client that can be wrong about a request should not be able to rewrite the
 * material every organization sees.
 *
 * Every method asks {@see ModuleAccess}, never `OrganizationAccess`: a module carries no
 * `organization_id`, and who may read one is decided by whether it has been switched on for the
 * caller's organization.
 */
final readonly class ModuleController
{
    /** Returns a page of the modules available to the caller's organization. */
    public function index(IndexModulesRequest $request): Responsable
    {
        /** @var User $actor */
        $actor = $request->user();

        $query = ModuleAccess::readable($actor)->with('category');

        $search = $request->validated('search');

        if (is_string($search) && trim($search) !== '') {
            // Folded on both sides, like every other search here. A module has no normalized column
            // of its own — its name is deliberately not unique — so the fold is applied to the
            // column in the query instead.
            $query->whereRaw(
                'UPPER(name) LIKE ? ESCAPE ?',
                [SearchPattern::contains(trim($search)), SearchPattern::ESCAPE_CHARACTER],
            );
        }

        $category = $request->validated('category');

        if (is_string($category) && $category !== '') {
            $query->where('category_id', $category);
        }

        $status = $request->validated('status');

        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        $page = PaginatedList::create(
            // Newest first, as the panel's table is ordered. The key is a UUIDv7, so it breaks ties
            // in the same direction rather than arbitrarily.
            $query->orderByDesc('created_at')->orderByDesc('id'),
            $request->pageNumber(),
            $request->pageSize(),
        );

        return new PaginatedCollection($page, ModuleSummaryResource::class);
    }

    /** Returns one module, including whatever the caller's own organization added to it. */
    public function show(Request $request, Module $module): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        // Refuses and resolves in one lookup: for everybody but a platform administrator, whether
        // they may read this module is the same question as which activation is theirs.
        $activation = ModuleAccess::resolveActivation($actor, $module);

        $module->load([
            'category',
            'videos',
            'links',
            'eLearnings' => fn ($courses) => $courses->withCount('chapters'),
        ]);

        $activation?->load(['videos', 'links', 'contacts']);

        return (new ModuleResource($module, $activation))->response();
    }

    /** Returns the module's picture, for the modules that have one. */
    public function image(Request $request, Module $module): Response
    {
        /** @var User $actor */
        $actor = $request->user();

        ModuleAccess::ensureCanRead($actor, $module);

        $image = $module->image();

        if ($image === null) {
            throw new NotFoundException('Deze module heeft geen afbeelding.');
        }

        $served = ServedFile::for($image);

        return response($served->content, HttpResponse::HTTP_OK, $served->headers());
    }

    /**
     * Returns the bytes of an uploaded video.
     *
     * The module is named in the address and checked first, and the video is then confirmed to be
     * one of that module's — either the platform's own or the reading organization's. Without that
     * second half, a video identifier alone would reach any organization's upload.
     */
    public function videoFile(Request $request, Module $module, ModuleVideo $video): Response
    {
        /** @var User $actor */
        $actor = $request->user();

        $activation = ModuleAccess::resolveActivation($actor, $module);

        $belongsHere = $video->module_id === $module->getKey()
            || ($activation !== null && $video->module_activation_id === $activation->getKey());

        if (! $belongsHere) {
            throw new NotFoundException('Deze video hoort niet bij deze module.');
        }

        $file = $video->file();

        if ($file === null) {
            throw new NotFoundException('Deze video is een link en heeft geen bestand.');
        }

        $served = ServedFile::for($file);

        return response($served->content, HttpResponse::HTTP_OK, $served->headers());
    }
}
