<?php

declare(strict_types=1);

namespace App\Support\Videos;

use App\Support\Files\ServedFile;
use App\Support\Files\StoredFile;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * An uploaded video on its way to a player: a redirect to a short-lived, read-only link.
 *
 * Not the bytes, which is the difference from {@see ServedFile}. A video is
 * too large to read into a PHP process, and a player asks for it a range at a time as somebody
 * seeks — Azure answers those itself, which is also what makes a video play on an iPhone at all.
 * The permission question has already been asked by whoever returns this; the link is what the
 * answer is worth, and it expires.
 *
 * The media type is the one read out of the bytes when the upload was completed, stated on the
 * link rather than left to whatever the browser declared as it wrote the blob.
 */
final readonly class VideoPlayback implements Responsable
{
    private function __construct(public string $url) {}

    public static function for(StoredFile $file): self
    {
        $expiresAt = Carbon::now()->addMinutes((int) config('kompaz.videos.playback_minutes'));

        return new self(VideoStorage::disk()->temporaryUrl($file->key, $expiresAt, [
            'httpHeaders' => ['contentType' => $file->contentType],
        ]));
    }

    public function toResponse($request): RedirectResponse
    {
        // Never cached: the link inside it expires, and a cached redirect would outlive it.
        return new RedirectResponse($this->url, HttpResponse::HTTP_FOUND, [
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
