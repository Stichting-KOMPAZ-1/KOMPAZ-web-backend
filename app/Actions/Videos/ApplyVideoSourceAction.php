<?php

declare(strict_types=1);

namespace App\Actions\Videos;

use App\Models\Contracts\HoldsVideo;
use App\Models\User;
use App\Support\Videos\VideoMessages;
use Illuminate\Validation\ValidationException;

/**
 * Points a video at what a form or a request said it should show.
 *
 * One implementation for a module's videos and a step's video blocks, through both doors each:
 *
 *  - **A link or an upload, never both.** Refused in Dutch rather than resolved by precedence,
 *    since either guess would throw away something the caller meant.
 *  - **An upload is claimed**, which is what makes it the caller's own and spends it.
 *  - **Neither keeps the file the row already has**, so an edit need not upload a video again —
 *    and a row with no file of its own that is sent neither is refused.
 *
 * Nothing here saves the row: the caller does, in the transaction the claim belongs to.
 */
final readonly class ApplyVideoSourceAction
{
    public function __construct(private ClaimVideoUploadAction $claim) {}

    public function execute(
        User $actor,
        HoldsVideo $video,
        ?string $url,
        ?string $uploadId,
        string $urlField,
        string $uploadField,
    ): void {
        if ($url !== null && $uploadId !== null) {
            throw ValidationException::withMessages([$uploadField => VideoMessages::LINK_OR_UPLOAD]);
        }

        if ($uploadId !== null) {
            $video->applyFile($this->claim->execute($actor, $uploadId, $uploadField));

            return;
        }

        if ($url !== null) {
            $video->applyVideoUrl($url);

            return;
        }

        if ($video->file() === null) {
            throw ValidationException::withMessages([$urlField => VideoMessages::NEEDS_SOURCE]);
        }
    }
}
