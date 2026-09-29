<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Modules\DeleteModuleAction;
use App\Actions\Modules\RemoveModuleImageAction;
use App\Actions\Modules\SaveModuleAction;
use App\Actions\Modules\SetModuleImageAction;
use App\Exceptions\NotFoundException;
use App\Http\Requests\IndexModulesRequest;
use App\Http\Requests\SaveModuleRequest;
use App\Http\Requests\UploadContentImageRequest;
use App\Http\Resources\ModuleResource;
use App\Http\Resources\ModuleSummaryResource;
use App\Http\Resources\PaginatedCollection;
use App\Models\Module;
use App\Models\ModuleActivation;
use App\Models\ModuleVideo;
use App\Models\User;
use App\Support\Access\ModuleAccess;
use App\Support\Files\ServedFile;
use App\Support\Modules\ContentRules;
use App\Support\Pagination\PaginatedList;
use App\Support\Search\SearchPattern;
use App\Support\Videos\VideoPlayback;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The modules an organization has been given, as its people read them — and, for the platform,
 * the module itself to write.
 *
 * Reading asks {@see ModuleAccess}, never `OrganizationAccess`: a module carries no
 * `organization_id`, and who may read one is decided by whether it has been switched on for the
 * caller's organization. Writing is the platform's alone and is asked again in every action; the
 * rules it keeps are the panel's form's, from {@see ContentRules}.
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

        return new PaginatedCollection($page, ModuleSummaryResource::collection($page->items));
    }

    /** Returns one module, including whatever the caller's own organization added to it. */
    public function show(Request $request, Module $module): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        // Refuses and resolves in one lookup: for everybody but a platform administrator, whether
        // they may read this module is the same question as which activation is theirs.
        $activation = ModuleAccess::resolveActivation($actor, $module);

        return self::detail($module, $activation)->response();
    }

    /** Creates a module. Its picture, if it has one, is its own endpoint. */
    public function store(SaveModuleRequest $request, SaveModuleAction $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $module = $action->execute($actor, new Module, $request->details());

        return self::detail($module, null)
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED)
            ->header('Location', '/api/modules/'.$module->getKey());
    }

    /** Writes a new version of a module. A list left out of the body is left as it is. */
    public function update(SaveModuleRequest $request, Module $module, SaveModuleAction $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return self::detail($action->execute($actor, $module, $request->details()), null)->response();
    }

    /** Deletes a module for good. Its courses are unlinked and survive it. */
    public function destroy(Request $request, Module $module, DeleteModuleAction $action): Response
    {
        /** @var User $actor */
        $actor = $request->user();

        $action->execute($actor, $module);

        return response()->noContent();
    }

    /** Gives the module a picture, or a new one. */
    public function updateImage(UploadContentImageRequest $request, Module $module, SetModuleImageAction $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return self::detail($action->execute($actor, $module, $request->picture()), null)->response();
    }

    /** Leaves the module with no picture. */
    public function destroyImage(Request $request, Module $module, RemoveModuleImageAction $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return self::detail($action->execute($actor, $module), null)->response();
    }

    /**
     * A module as its detail response draws it, under the reader's own copy or none.
     *
     * A write answers with the platform's view — no activation — because whoever may write a
     * module is the platform, which reads it at no organization.
     */
    private static function detail(Module $module, ?ModuleActivation $activation): ModuleResource
    {
        $module->load([
            'category',
            'videos',
            'links',
            'eLearnings' => fn ($courses) => $courses->withCount('chapters'),
        ]);

        $activation?->load(['videos', 'links', 'contacts']);

        return new ModuleResource($module, $activation);
    }

    /** Returns the module's picture, for the modules that have one. */
    public function image(Request $request, Module $module): ServedFile
    {
        /** @var User $actor */
        $actor = $request->user();

        ModuleAccess::ensureCanRead($actor, $module);

        $image = $module->image();

        if ($image === null) {
            throw new NotFoundException('Deze module heeft geen afbeelding.');
        }

        return ServedFile::for($image);
    }

    /**
     * Sends a player on to an uploaded video.
     *
     * The module is named in the address and checked first, and the video is then confirmed to be
     * one of that module's — either the platform's own or the reading organization's. Without that
     * second half, a video identifier alone would reach any organization's upload.
     *
     * A redirect to a short-lived, read-only link rather than the bytes: a video is read from Azure
     * a range at a time as somebody watches, which a PHP process holding the file could not do.
     */
    public function videoFile(Request $request, Module $module, ModuleVideo $video): VideoPlayback
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

        return VideoPlayback::for($file);
    }
}
