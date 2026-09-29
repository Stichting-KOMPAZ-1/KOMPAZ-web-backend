<?php

declare(strict_types=1);

namespace App\Actions\Videos;

use App\Events\ContentFileDiscarded;
use App\Models\User;
use App\Models\VideoUpload;
use App\Support\Access\OrganizationAccess;
use App\Support\Videos\IssuedVideoUpload;
use App\Support\Videos\VideoStorage;
use Illuminate\Support\Carbon;

/**
 * Gives somebody leave to write one video, straight to the video container.
 *
 * The bytes never pass through this application: a PHP request would not survive two gigabytes,
 * and it would carry them twice. What this hands back is a link that can create one blob — the key
 * is minted here, never accepted — and expires. The browser then writes it in blocks, and
 * {@see CompleteVideoUploadAction} looks at what arrived.
 *
 * Anybody who can manage an organization's content may upload: a platform administrator for the
 * platform's modules and courses, an organization administrator for their own copy of a module.
 * Which of those a finished upload ends up on is asked again by whatever saves it.
 */
final readonly class IssueVideoUploadAction
{
    /**
     * How many abandoned uploads one issue clears up.
     *
     * There is no scheduler (docs/deployment.md), so an upload somebody started and never used is
     * cleared by the next one somebody starts. A few at a time: each is a request to Azure, and
     * this is somebody waiting for their upload to begin.
     */
    private const int SWEEP_LIMIT = 5;

    public function execute(User $actor, int $declaredByteCount): IssuedVideoUpload
    {
        OrganizationAccess::ensureCanManage($actor, $actor->organization_id);

        $this->sweepAbandoned();

        $expiresAt = Carbon::now()->addMinutes((int) config('kompaz.videos.upload_minutes'));

        $upload = new VideoUpload([
            'issued_to' => $actor->getKey(),
            'declared_byte_count' => $declaredByteCount,
            'expires_at' => $expiresAt,
        ]);
        $upload->setAttribute('id', $upload->newUniqueId());
        $upload->storage_key = VideoStorage::keyFor((string) $upload->getKey());
        $upload->save();

        $link = VideoStorage::disk()->temporaryUploadUrl($upload->storage_key, $expiresAt);

        return new IssuedVideoUpload($upload, $link['url'], $link['headers']);
    }

    /**
     * Lets go of uploads nobody claimed before their link ran out.
     *
     * An expired upload can no longer be written to or claimed — both are refused by its
     * expiry — so nothing can be racing for one of these. The row goes before the blob, as every
     * file here does: a failure leaves an orphaned blob rather than a row naming nothing.
     */
    private function sweepAbandoned(): void
    {
        $abandoned = VideoUpload::query()
            ->whereNull('claimed_at')
            ->where('expires_at', '<', Carbon::now())
            ->orderBy('expires_at')
            ->limit(self::SWEEP_LIMIT)
            ->get();

        foreach ($abandoned as $upload) {
            $upload->delete();

            ContentFileDiscarded::dispatch($upload->storage_key);
        }
    }
}
