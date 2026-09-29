<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\NotFoundException;
use App\Http\Resources\ELearningResource;
use App\Http\Resources\StepResource;
use App\Models\ContentBlock;
use App\Models\ELearning;
use App\Models\Step;
use App\Models\User;
use App\Support\Access\ModuleAccess;
use App\Support\Files\ServedFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A course, as somebody working through it reads it.
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
    /** Returns a course with its chapters and the names of their steps. */
    public function show(Request $request, ELearning $eLearning): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        ModuleAccess::ensureCanReadCourse($actor, $eLearning);

        $eLearning->load(['chapters.steps']);

        return ELearningResource::make($eLearning)->response();
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

    /** Returns the bytes behind a picture block or an uploaded video block. */
    public function blockFile(
        Request $request,
        ELearning $eLearning,
        Step $step,
        ContentBlock $block,
    ): ServedFile {
        /** @var User $actor */
        $actor = $request->user();

        ModuleAccess::ensureCanReadCourse($actor, $eLearning);

        self::ensureStepBelongsToCourse($eLearning, $step);

        if ($block->step_id !== $step->getKey()) {
            throw new NotFoundException('Dit blok hoort niet bij deze stap.');
        }

        $file = $block->file();

        if ($file === null) {
            throw new NotFoundException('Dit blok heeft geen bestand.');
        }

        return ServedFile::for($file);
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

        throw new NotFoundException('Deze stap hoort niet bij deze e-learning.');
    }
}
