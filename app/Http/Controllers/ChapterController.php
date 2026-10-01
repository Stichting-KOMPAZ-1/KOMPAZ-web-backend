<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Modules\DeleteChapterAction;
use App\Actions\Modules\ReorderContentAction;
use App\Actions\Modules\SaveChapterAction;
use App\Http\Requests\ReorderContentRequest;
use App\Http\Requests\SaveChapterRequest;
use App\Http\Resources\ChapterResource;
use App\Http\Resources\ELearningResource;
use App\Models\Chapter;
use App\Models\ELearning;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * A course's chapters, as the platform writes them.
 *
 * Nested under their course, and bound through it (`scopeBindings()` on the routes): a chapter
 * named under a course it does not belong to is not found, rather than trusted from its own key.
 */
final readonly class ChapterController
{
    /** Adds a chapter at the end of the course. */
    public function store(SaveChapterRequest $request, ELearning $eLearning, SaveChapterAction $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $chapter = $action->execute($actor, $eLearning->chapters()->make(), $request->details());

        return ChapterResource::make($chapter->load('steps'))
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }

    /** Edits a chapter. Its place in the course is the order endpoint's. */
    public function update(SaveChapterRequest $request, ELearning $eLearning, Chapter $chapter, SaveChapterAction $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return ChapterResource::make($action->execute($actor, $chapter, $request->details())->load('steps'))->response();
    }

    /** Deletes a chapter for good, with its parts. */
    public function destroy(Request $request, ELearning $eLearning, Chapter $chapter, DeleteChapterAction $action): Response
    {
        /** @var User $actor */
        $actor = $request->user();

        $action->execute($actor, $chapter);

        return response()->noContent();
    }

    /**
     * Puts the course's chapters in a new order, and answers with the course.
     *
     * The keys may be some of the chapters rather than all: the ones named move among the places
     * they already held and the rest stay put, as a drag in the panel does.
     */
    public function reorder(ReorderContentRequest $request, ELearning $eLearning, ReorderContentAction $action): ELearningResource
    {
        /** @var User $actor */
        $actor = $request->user();

        $action->execute($actor, $eLearning->chapters(), $request->ids());

        return ELearningController::tree($eLearning);
    }
}
