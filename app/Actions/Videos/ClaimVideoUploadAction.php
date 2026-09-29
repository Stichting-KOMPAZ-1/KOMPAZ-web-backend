<?php

declare(strict_types=1);

namespace App\Actions\Videos;

use App\Models\User;
use App\Models\VideoUpload;
use App\Support\Files\StoredFile;
use App\Support\Videos\VideoMessages;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Spends a finished upload on the video that will show it.
 *
 * Rule 4's shape, and for rule 4's reason: every reason to refuse — somebody else's, not finished,
 * already used, expired — is a condition of one UPDATE, and the affected-row count decides. Two
 * saves naming the same upload cannot both get it, which matters twice over here: a second video
 * pointing at the same blob would lose its bytes the moment the first was deleted, and an upload
 * that could be named by anyone who learned its key would let one organization show another's
 * video under its own module.
 *
 * Called inside the save that writes the video, so a save that fails afterwards rolls the claim
 * back with it and the upload can be named again.
 */
final readonly class ClaimVideoUploadAction
{
    /** @param  string  $field  where in the request the upload was named, for the refusal */
    public function execute(User $actor, string $uploadId, string $field): StoredFile
    {
        $now = Carbon::now();

        $claimed = VideoUpload::query()
            ->whereKey($uploadId)
            ->where('issued_to', $actor->getKey())
            ->whereNotNull('verified_at')
            ->whereNull('claimed_at')
            ->where('expires_at', '>', $now)
            ->update(['claimed_at' => $now]);

        if ($claimed === 0) {
            throw ValidationException::withMessages([$field => $this->refusalFor($actor, $uploadId, $now)]);
        }

        $file = VideoUpload::query()->findOrFail($uploadId)->file();

        if ($file === null) {
            throw new LogicException('A claimed upload has to have been verified first.');
        }

        return $file;
    }

    /**
     * Which sentence a refused claim gets.
     *
     * Read after the UPDATE has already refused, so it decides only the wording and never the
     * outcome. "Not finished yet" is worth saying separately: it is the one an operator can fix by
     * waiting, and it only ever describes their own upload.
     */
    private function refusalFor(User $actor, string $uploadId, Carbon $now): string
    {
        $unfinished = VideoUpload::query()
            ->whereKey($uploadId)
            ->where('issued_to', $actor->getKey())
            ->whereNull('verified_at')
            ->where('expires_at', '>', $now)
            ->exists();

        return $unfinished ? VideoMessages::UPLOAD_INCOMPLETE : VideoMessages::UPLOAD_UNAVAILABLE;
    }
}
