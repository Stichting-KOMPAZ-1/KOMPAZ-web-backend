<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Modules\DeleteStepAction;
use App\Actions\Modules\ReorderContentAction;
use App\Actions\Modules\SaveStepAction;
use App\Http\Requests\ReorderContentRequest;
use App\Http\Requests\SaveStepRequest;
use App\Http\Resources\ChapterResource;
use App\Http\Resources\StepResource;
use App\Models\Chapter;
use App\Models\ELearning;
use App\Models\Step;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * A chapter's steps and every block on them, as the platform writes them.
 *
 * Nested under their chapter under their course, and bound through both: a step named under a
 * chapter it does not belong to is not found. Reading a step stays where it was,
 * `GET /api/e-learnings/{eLearning}/steps/{step}`, which is what a reader follows.
 */
final readonly class StepController
{
    /** Adds a step at the end of the chapter. Multipart when a block carries a picture. */
    public function store(SaveStepRequest $request, ELearning $eLearning, Chapter $chapter, SaveStepAction $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $step = $action->execute($actor, $chapter->steps()->make(), $request->name(), $request->blocks());

        return self::detail($step, $eLearning)
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED)
            ->header('Location', '/api/e-learnings/'.$eLearning->getKey().'/steps/'.$step->getKey());
    }

    /**
     * Writes a new version of a step: its name and the whole list of its blocks.
     *
     * A picture block that keeps its picture sends its `id` and no `image`. Carrying a new picture
     * makes this multipart, and so a POST with `_method=PUT`.
     */
    public function update(SaveStepRequest $request, ELearning $eLearning, Chapter $chapter, Step $step, SaveStepAction $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return self::detail($action->execute($actor, $step, $request->name(), $request->blocks()), $eLearning)->response();
    }

    /** Deletes a step for good, with its blocks. */
    public function destroy(Request $request, ELearning $eLearning, Chapter $chapter, Step $step, DeleteStepAction $action): Response
    {
        /** @var User $actor */
        $actor = $request->user();

        $action->execute($actor, $step);

        return response()->noContent();
    }

    /** Puts the chapter's steps in a new order, and answers with the chapter. */
    public function reorder(ReorderContentRequest $request, ELearning $eLearning, Chapter $chapter, ReorderContentAction $action): ChapterResource
    {
        /** @var User $actor */
        $actor = $request->user();

        $action->execute($actor, $chapter->steps(), $request->ids());

        return ChapterResource::make($chapter->load('steps'));
    }

    private static function detail(Step $step, ELearning $eLearning): StepResource
    {
        return new StepResource($step->load('blocks'), (string) $eLearning->getKey());
    }
}
