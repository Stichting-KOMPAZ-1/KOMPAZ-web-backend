<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Videos\CompleteVideoUploadAction;
use App\Actions\Videos\IssueVideoUploadAction;
use App\Http\Requests\IssueVideoUploadRequest;
use App\Http\Resources\VideoUploadResource;
use App\Models\User;
use App\Models\VideoUpload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Uploading a video: asking for a link to write it to, and saying it has been written.
 *
 * Served twice — under `/api` for the frontend, where an organization administrator adds videos to
 * their own copy of a module, and under `/nova-vendor` for the panel's upload field — because the
 * frontend's nginx sends only the panel's paths here, and the panel cannot send a token. Both are
 * this controller and the same two use cases; which video an upload ends up on, and whether the
 * caller may put it there, is asked by the save that names it.
 */
final readonly class VideoUploadController
{
    /** Issues an upload link for one video of the size stated. */
    public function store(IssueVideoUploadRequest $request, IssueVideoUploadAction $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $issued = $action->execute($actor, $request->byteCount());

        return (new VideoUploadResource($issued->upload, $issued->url, $issued->headers))
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }

    /**
     * Looks at what was written and keeps it if it is a video.
     *
     * Somebody else's upload, a used one and an expired one are all answered 404 alike.
     */
    public function complete(Request $request, VideoUpload $videoUpload, CompleteVideoUploadAction $action): VideoUploadResource
    {
        /** @var User $actor */
        $actor = $request->user();

        return new VideoUploadResource($action->execute($actor, $videoUpload));
    }
}
