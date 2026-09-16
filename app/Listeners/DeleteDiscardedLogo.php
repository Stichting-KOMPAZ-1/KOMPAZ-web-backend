<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\OrganizationLogoDiscarded;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Removes the file a row has stopped pointing at.
 *
 * Runs after the commit, which is the ordering the whole design turns on: an upload writes its
 * bytes *before* its row, and a deletion removes its row *before* its bytes, so every failure
 * leaves an orphaned file rather than a row pointing at nothing. An orphan costs storage and is
 * logged with its key; a dangling pointer would be a broken image on somebody's screen. Never
 * "fix" this by deleting the file first.
 */
final class DeleteDiscardedLogo
{
    public function handle(OrganizationLogoDiscarded $event): void
    {
        try {
            Storage::delete($event->storageKey);
        } catch (Throwable $exception) {
            Log::error('Failed to remove a discarded logo. The file is now orphaned.', [
                'storage_key' => $event->storageKey,
                'exception' => $exception,
            ]);
        }
    }
}
