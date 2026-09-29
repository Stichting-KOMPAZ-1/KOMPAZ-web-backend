<?php

declare(strict_types=1);

namespace App\Support\Videos;

/**
 * What a video's upload and its source are refused with, in the product's Dutch.
 *
 * In one place because three doors say them — the panel's forms, the API's module and step
 * requests, and the upload endpoints both of those call — and a module video and a video block are
 * refused for the same things in the same sentences.
 */
final class VideoMessages
{
    /** A video with neither a link nor an upload, and no file of its own already. */
    public const string NEEDS_SOURCE = 'Vul een link in of upload een video.';

    /** A video sent both at once: it is one or the other, never both. */
    public const string LINK_OR_UPLOAD = 'Kies een link of een upload, niet allebei.';

    /** An upload that is not the caller's, has been used, has expired, or never existed. */
    public const string UPLOAD_UNAVAILABLE = 'Deze upload is verlopen of niet gevonden. Upload de video opnieuw.';

    /** An upload named before its bytes were all written and looked at. */
    public const string UPLOAD_INCOMPLETE = 'De video is nog niet volledig geüpload.';

    /** Bytes that are none of the formats a browser plays. */
    public const string NOT_A_VIDEO = 'Dit bestand is geen video. Upload een MP4-, MOV- of WebM-bestand.';

    /** The largest video accepted, in bytes. */
    public static function maximumBytes(): int
    {
        return (int) config('kompaz.videos.maximum_size_bytes');
    }

    public static function tooLarge(): string
    {
        return sprintf('Een video mag maximaal %d GB zijn.', intdiv(self::maximumBytes(), 1024 ** 3));
    }
}
