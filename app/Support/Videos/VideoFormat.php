<?php

declare(strict_types=1);

namespace App\Support\Videos;

/**
 * What a video's first bytes say it is.
 *
 * The same rule a logo follows (rule 12): the media type is read out of the content and never
 * taken from what the browser declared, because it is what the player is later handed. Only the
 * three containers every browser a KOMPAZ reader uses can play are recognised — MP4, QuickTime
 * (what a phone records) and WebM. Anything else is not a video to this application, even if it
 * is one to somebody's desktop player.
 */
final class VideoFormat
{
    public const string MP4 = 'video/mp4';

    public const string QUICKTIME = 'video/quicktime';

    public const string WEBM = 'video/webm';

    /** How much of the start of a file {@see detect()} needs to see. */
    public const int HEAD_LENGTH = 64;

    /**
     * The media type, or null when the bytes are none of the three.
     *
     * MP4 and QuickTime share the ISO base media format: a box whose type, four bytes in, is
     * `ftyp`, followed by the brand — `qt  ` for QuickTime and anything else for MP4. WebM is
     * Matroska, whose EBML header opens with four fixed bytes and names its document type near
     * the start; a Matroska file that is not WebM is refused, since Safari plays none of them.
     */
    public static function detect(string $head): ?string
    {
        if (strlen($head) >= 12 && substr($head, 4, 4) === 'ftyp') {
            return substr($head, 8, 4) === 'qt  ' ? self::QUICKTIME : self::MP4;
        }

        if (str_starts_with($head, "\x1A\x45\xDF\xA3") && str_contains($head, 'webm')) {
            return self::WEBM;
        }

        return null;
    }
}
