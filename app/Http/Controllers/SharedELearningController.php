<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\ELearningResource;
use App\Http\Resources\PartResource;
use App\Models\ContentBlock;
use App\Models\Step;
use App\Support\Access\ModuleAccess;
use App\Support\Files\ServedFile;
use App\Support\Modules\CourseContent;
use App\Support\Videos\VideoPlayback;
use Illuminate\Http\JsonResponse;

/**
 * A course as an organization shares it, for whoever followed the link — with no account (KOM-95).
 *
 * An organization hands its people `/{organization}/{e-learning}`, or the QR code that leads to
 * `/{organization}/qr/{e-learning}`; the frontend serves both pages and reads the course from here.
 * The two slugs are all a visitor brings, so they are the whole of the permission: the course is
 * served while that organization has a module showing it ({@see ModuleAccess::resolveSharedCourse()})
 * and is a 404 the moment it does not. Nothing here writes, and nothing here says who is reading.
 *
 * The same shapes the signed-in endpoints answer with, so the frontend draws one course page for
 * both; every address inside them hangs off this door's path, so a visitor is never sent to one
 * that asks them to sign in.
 */
final readonly class SharedELearningController
{
    /** Returns the course with its chapters and the names of their parts. */
    public function show(string $organizationSlug, string $eLearningSlug): JsonResponse
    {
        $course = ModuleAccess::resolveSharedCourse($organizationSlug, $eLearningSlug);

        return ELearningResource::make($course->eLearning->load(['chapters.steps']))
            ->readThrough($course->path())
            ->response();
    }

    /** Returns the course's picture. */
    public function image(string $organizationSlug, string $eLearningSlug): ServedFile
    {
        $course = ModuleAccess::resolveSharedCourse($organizationSlug, $eLearningSlug);

        return ServedFile::for($course->eLearning->image());
    }

    /** Returns one part with every block on it, in the order they are drawn. */
    public function part(string $organizationSlug, string $eLearningSlug, Step $part): JsonResponse
    {
        $course = ModuleAccess::resolveSharedCourse($organizationSlug, $eLearningSlug);

        CourseContent::ensurePartBelongsToCourse($course->eLearning, $part);

        return (new PartResource($part->load('blocks'), $course->path()))->response();
    }

    /** Returns the bytes behind a picture block, or sends a player on to an uploaded video block. */
    public function blockFile(
        string $organizationSlug,
        string $eLearningSlug,
        Step $part,
        ContentBlock $block,
    ): ServedFile|VideoPlayback {
        $course = ModuleAccess::resolveSharedCourse($organizationSlug, $eLearningSlug);

        CourseContent::ensurePartBelongsToCourse($course->eLearning, $part);

        return CourseContent::blockFile($part, $block);
    }
}
