<?php

declare(strict_types=1);

namespace App\Actions\Videos;

use App\Events\ContentFileDiscarded;
use App\Exceptions\NotFoundException;
use App\Models\User;
use App\Models\VideoUpload;
use App\Support\Videos\VideoFormat;
use App\Support\Videos\VideoMessages;
use App\Support\Videos\VideoStorage;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Looks at what a browser wrote, and keeps it only if it is a video.
 *
 * Its own step, called when the browser has written the last block, rather than part of saving the
 * form the video is on. Two reasons. It reads from Azure, which does not belong inside the
 * transaction a save holds open. And an operator who picked the wrong file learns so while they
 * are still looking at it, rather than when they press save after typing everything else.
 *
 * Rule 12 in a different shape: the media type is read out of the first bytes, never taken from
 * what the browser declared, and the size is read off the blob rather than believed from the
 * request that asked for the link. A file that is neither acceptable nor small enough is removed
 * at once — nobody will ever be able to claim it.
 */
final readonly class CompleteVideoUploadAction
{
    public function execute(User $actor, VideoUpload $upload): VideoUpload
    {
        // One answer for every reason, and a 404: whether somebody else has an upload under this
        // key is not something to learn by guessing keys.
        if ($upload->issued_to !== $actor->getKey()
            || $upload->claimed_at !== null
            || $upload->expires_at->isPast()) {
            throw new NotFoundException(VideoMessages::UPLOAD_UNAVAILABLE);
        }

        // Completing twice is asking the same question twice, so it gets the same answer.
        if ($upload->isVerified()) {
            return $upload;
        }

        $disk = VideoStorage::disk();

        if (! $disk->exists($upload->storage_key)) {
            throw ValidationException::withMessages(['upload' => VideoMessages::UPLOAD_INCOMPLETE]);
        }

        $byteCount = $disk->size($upload->storage_key);

        if ($byteCount > VideoMessages::maximumBytes()) {
            $this->discard($upload, VideoMessages::tooLarge());
        }

        $contentType = VideoFormat::detect($this->head($upload->storage_key));

        if ($contentType === null) {
            $this->discard($upload, VideoMessages::NOT_A_VIDEO);
        }

        $upload->content_type = $contentType;
        $upload->byte_count = $byteCount;
        $upload->verified_at = Carbon::now();
        $upload->save();

        return $upload;
    }

    /**
     * The first bytes of the blob, and no more.
     *
     * A streamed read that is closed after the first chunk, so a two-gigabyte video costs one
     * range of it rather than the whole thing.
     */
    private function head(string $key): string
    {
        $stream = VideoStorage::disk()->readStream($key);

        if ($stream === null) {
            throw ValidationException::withMessages(['upload' => VideoMessages::UPLOAD_INCOMPLETE]);
        }

        try {
            $head = fread($stream, VideoFormat::HEAD_LENGTH);
        } finally {
            fclose($stream);
        }

        return $head === false ? '' : $head;
    }

    /** Removes an upload nobody may use, and says why. */
    private function discard(VideoUpload $upload, string $reason): never
    {
        $upload->delete();

        ContentFileDiscarded::dispatch($upload->storage_key);

        throw ValidationException::withMessages(['upload' => $reason]);
    }
}
