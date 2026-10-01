<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Modules\DeleteELearningAction;
use App\Actions\Modules\SaveELearningAction;
use App\Actions\Modules\SetELearningImageAction;
use App\Enums\ContentBlockType;
use App\Exceptions\NotFoundException;
use App\Http\Requests\IndexELearningsRequest;
use App\Http\Requests\SaveELearningRequest;
use App\Http\Requests\UploadContentImageRequest;
use App\Http\Resources\ELearningListResource;
use App\Http\Resources\ELearningResource;
use App\Http\Resources\PaginatedCollection;
use App\Http\Resources\StepResource;
use App\Models\ContentBlock;
use App\Models\ELearning;
use App\Models\Step;
use App\Models\User;
use App\Support\Access\ModuleAccess;
use App\Support\Files\ServedFile;
use App\Support\Pagination\PaginatedList;
use App\Support\Search\SearchPattern;
use App\Support\Videos\VideoPlayback;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * A course, as somebody working through it reads it — and, for the platform, the course itself to
 * write. Its chapters and steps are written through {@see ChapterController} and
 * {@see StepController}.
 *
 * Two shapes, on purpose. The course itself answers with its whole table of contents — every
 * chapter and the name of every step — because that is the sidebar, and fetching it a chapter at a
 * time would be a request per heading. The content of a step is fetched one step at a time, as it
 * is read, because the alternative is the entire course on the first request.
 *
 * A course is reached through the modules that show it, so that is where permission comes from.
 * Everything nested below is confirmed to belong to the course named in the address rather than
 * trusted from its own identifier.
 */
final readonly class ELearningController
{
    /** Returns a page of the courses the caller may read, each with the modules that show it. */
    public function index(IndexELearningsRequest $request): Responsable
    {
        /** @var User $actor */
        $actor = $request->user();

        $query = ModuleAccess::readableCourses($actor)->withCount('chapters');

        $search = $request->validated('search');

        if (is_string($search) && trim($search) !== '') {
            $query->whereRaw(
                'UPPER(name) LIKE ? ESCAPE ?',
                [SearchPattern::contains(trim($search)), SearchPattern::ESCAPE_CHARACTER],
            );
        }

        $page = PaginatedList::create(
            // Newest first, as the panel's table is ordered.
            $query->orderByDesc('created_at')->orderByDesc('id'),
            $request->pageNumber(),
            $request->pageSize(),
        );

        return new PaginatedCollection($page, ELearningListResource::collection($page->items));
    }

    /** Creates a course with its picture. Multipart, because the picture is required. */
    public function store(SaveELearningRequest $request, SaveELearningAction $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $eLearning = $action->execute($actor, new ELearning, $request->name(), $request->moduleIds(), $request->picture());

        return self::tree($eLearning)
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED)
            ->header('Location', '/api/e-learnings/'.$eLearning->getKey());
    }

    /** Renames a course, and says which modules show it when the body says. */
    public function update(SaveELearningRequest $request, ELearning $eLearning, SaveELearningAction $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return self::tree($action->execute($actor, $eLearning, $request->name(), $request->moduleIds()))->response();
    }

    /** Deletes a course for good, with its chapters and steps. The modules that showed it survive. */
    public function destroy(Request $request, ELearning $eLearning, DeleteELearningAction $action): Response
    {
        /** @var User $actor */
        $actor = $request->user();

        $action->execute($actor, $eLearning);

        return response()->noContent();
    }

    /** Replaces the course's picture. */
    public function updateImage(UploadContentImageRequest $request, ELearning $eLearning, SetELearningImageAction $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return self::tree($action->execute($actor, $eLearning, $request->picture()))->response();
    }

    /** Returns a course with its chapters and the names of their steps. */
    public function show(Request $request, ELearning $eLearning): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        ModuleAccess::ensureCanReadCourse($actor, $eLearning);

        return self::tree($eLearning)->response();
    }

    /** A course with its whole table of contents, which is what every response about one draws. */
    public static function tree(ELearning $eLearning): ELearningResource
    {
        return ELearningResource::make($eLearning->load(['chapters.steps']));
    }

    /** Returns the course's picture. */
    public function image(Request $request, ELearning $eLearning): ServedFile
    {
        /** @var User $actor */
        $actor = $request->user();

        ModuleAccess::ensureCanReadCourse($actor, $eLearning);

        return ServedFile::for($eLearning->image());
    }

    /** Returns one step with every block on it, in the order they are drawn. */
    public function step(Request $request, ELearning $eLearning, Step $step): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        ModuleAccess::ensureCanReadCourse($actor, $eLearning);

        self::ensureStepBelongsToCourse($eLearning, $step);

        $step->load('blocks');

        return (new StepResource($step, (string) $eLearning->getKey()))->response();
    }

    /**
     * Returns the bytes behind a picture block, or sends a player on to an uploaded video block.
     *
     * A video is a redirect to a short-lived, read-only link, for the reason a module's is
     * ({@see ModuleController::videoFile()}); a picture is small enough to hand back here.
     */
    public function blockFile(
        Request $request,
        ELearning $eLearning,
        Step $step,
        ContentBlock $block,
    ): ServedFile|VideoPlayback {
        /** @var User $actor */
        $actor = $request->user();

        ModuleAccess::ensureCanReadCourse($actor, $eLearning);

        self::ensureStepBelongsToCourse($eLearning, $step);

        if ($block->step_id !== $step->getKey()) {
            throw new NotFoundException('Dit blok hoort niet bij dit onderdeel.');
        }

        $file = $block->file();

        if ($file === null) {
            throw new NotFoundException('Dit blok heeft geen bestand.');
        }

        return $block->type === ContentBlockType::Video
            ? VideoPlayback::for($file)
            : ServedFile::for($file);
    }

    /**
     * Refuses a step that belongs to a different course.
     *
     * The permission was granted for the course in the address, so a step reached under it has to
     * be one of that course's — otherwise any course the caller can read would be a key to every
     * step on the platform.
     */
    private static function ensureStepBelongsToCourse(ELearning $eLearning, Step $step): void
    {
        $belongsHere = $eLearning->chapters()
            ->whereKey($step->chapter_id)
            ->exists();

        if ($belongsHere) {
            return;
        }

        throw new NotFoundException('Dit onderdeel hoort niet bij deze e-learning.');
    }
}
