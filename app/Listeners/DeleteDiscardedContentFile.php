<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ContentFileDiscarded;
use App\Support\Videos\VideoStorage;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Removes the file a content row has stopped pointing at.
 *
 * Runs after the commit, on the same ordering every file here follows: bytes are written before
 * the row that names them, and the row goes before the bytes. Every failure therefore leaves an
 * orphan rather than a row pointing at nothing — an orphan costs storage and is logged with its
 * key, a dangling pointer would be a gap on somebody's screen.
 *
 * A video's bytes are on the video disk and everything else's on the default one; the key says
 * which ({@see VideoStorage}), so the event carries nothing more.
 */
final class DeleteDiscardedContentFile
{
    public function handle(ContentFileDiscarded $event): void
    {
        try {
            VideoStorage::diskHolding($event->storageKey)->delete($event->storageKey);
        } catch (Throwable $exception) {
            Log::error('Failed to remove a discarded content file. The file is now orphaned.', [
                'storage_key' => $event->storageKey,
                'exception' => $exception,
            ]);
        }
    }
}
