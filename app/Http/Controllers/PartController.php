<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Modules\DeleteStepAction;
use App\Actions\Modules\ReorderContentAction;
use App\Actions\Modules\SaveStepAction;
use App\Http\Requests\ReorderContentRequest;
use App\Http\Requests\SavePartRequest;
use App\Http\Resources\ChapterResource;
use App\Http\Resources\PartResource;
use App\Models\Chapter;
use App\Models\ELearning;
use App\Models\Step;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * A chapter's parts and every block on them, as the platform writes them.
 *
 * A part is what the code and the table still call a step: the product renamed it ("onderdeel",
 * "part") after the schema was deployed, and the API says what the product says. Nested under
 * their chapter under their course, and bound through both: a part named under a chapter it does
 * not belong to is not found. Reading a part stays where it was,
 * `GET /api/e-learnings/{eLearning}/parts/{part}`, which is what a reader follows.
 */
final readonly class PartController
{
    /** Adds a part at the end of the chapter. Multipart when a block carries a picture. */
    public function store(SavePartRequest $request, ELearning $eLearning, Chapter $chapter, SaveStepAction $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $part = $action->execute($actor, $chapter->steps()->make(), $request->name(), $request->blocks());

        return self::detail($part, $eLearning)
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED)
            ->header('Location', '/api/e-learnings/'.$eLearning->getKey().'/parts/'.$part->getKey());
    }

    /**
     * Writes a new version of a part: its name and the whole list of its blocks.
     *
     * A picture block that keeps its picture sends its `id` and no `image`. Carrying a new picture
     * makes this multipart, and so a POST with `_method=PUT`.
     */
    public function update(SavePartRequest $request, ELearning $eLearning, Chapter $chapter, Step $part, SaveStepAction $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return self::detail($action->execute($actor, $part, $request->name(), $request->blocks()), $eLearning)->response();
    }

    /** Deletes a part for good, with its blocks. */
    public function destroy(Request $request, ELearning $eLearning, Chapter $chapter, Step $part, DeleteStepAction $action): Response
    {
        /** @var User $actor */
        $actor = $request->user();

        $action->execute($actor, $part);

        return response()->noContent();
    }

    /** Puts the chapter's parts in a new order, and answers with the chapter. */
    public function reorder(ReorderContentRequest $request, ELearning $eLearning, Chapter $chapter, ReorderContentAction $action): ChapterResource
    {
        /** @var User $actor */
        $actor = $request->user();

        $action->execute($actor, $chapter->steps(), $request->ids());

        return ChapterResource::make($chapter->load('steps'));
    }

    private static function detail(Step $part, ELearning $eLearning): PartResource
    {
        return new PartResource($part->load('blocks'), (string) $eLearning->getKey());
    }
}
