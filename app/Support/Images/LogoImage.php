<?php

declare(strict_types=1);

namespace App\Support\Images;

/**
 * What an organization logo is allowed to be: a size limit, and a short list of formats recognized
 * by their own bytes.
 *
 * The format is read out of the content rather than taken from the upload's `Content-Type` or its
 * file name, both of which the caller chooses freely. That matters because the value is stored and
 * later served back as the response's own media type: believing the upload would let somebody
 * store HTML under `image/png`, or a script under any of these, and have this API hand it to a
 * browser as the type it claimed.
 */
final class LogoImage
{
    /** How the limit is written in the messages that report it, so number and unit cannot drift. */
    public const string MAXIMUM_SIZE = '10 MB';

    /** The formats accepted, as they read in the message that reports a rejected one. */
    public const string ACCEPTED_FORMATS = 'PNG, JPEG, SVG of WebP';

    public const string PNG = 'image/png';

    public const string JPEG = 'image/jpeg';

    public const string SVG = 'image/svg+xml';

    public const string WEBP = 'image/webp';

    /**
     * How far into an SVG the root element is looked for. An SVG may open with a byte-order mark,
     * an XML declaration, a doctype and comments before it, and this is room for all of them
     * without reading a whole megabyte of something that is not XML at all.
     */
    private const int SVG_PROBE_LENGTH = 1024;

    public static function maximumSizeInBytes(): int
    {
        return (int) config('kompaz.logo.maximum_size_bytes');
    }

    public static function exceedsMaximumSize(int $byteCount): bool
    {
        return $byteCount > self::maximumSizeInBytes();
    }

    /**
     * The file extension, without a dot, for one of the accepted media types.
     *
     * Only used to name the stored file, which nothing reads back — the media type comes from the
     * row, not from the key. Worth doing anyway: a bucket somebody is browsing to work out what is
     * taking up space is far more use when the files say what they are.
     */
    public static function extensionFor(string $contentType): string
    {
        return match ($contentType) {
            self::PNG => 'png',
            self::JPEG => 'jpg',
            self::SVG => 'svg',
            self::WEBP => 'webp',
            default => 'bin',
        };
    }

    /**
     * The media type the bytes actually are, or null when they are not one of the accepted formats.
     */
    public static function detectContentType(string $content): ?string
    {
        if (str_starts_with($content, "\x89PNG\r\n\x1a\n")) {
            return self::PNG;
        }

        if (str_starts_with($content, "\xFF\xD8\xFF")) {
            return self::JPEG;
        }

        // A WebP file is a RIFF container whose four-byte form type says which kind. The length in
        // between is the file's own, so it is skipped rather than read.
        if (strlen($content) >= 12 && str_starts_with($content, 'RIFF') && substr($content, 8, 4) === 'WEBP') {
            return self::WEBP;
        }

        return self::isSvg($content) ? self::SVG : null;
    }

    /**
     * Recognizes an SVG, which unlike the others has no magic number — it is XML, so the evidence
     * is a document whose root element is `svg`.
     */
    private static function isSvg(string $content): bool
    {
        $probe = substr($content, 0, self::SVG_PROBE_LENGTH);

        // Only checked when the probe is the whole payload: one cut out of a longer file can end
        // mid-character through no fault of the file, while a lone invalid byte anywhere else would
        // otherwise be compared as though it were text.
        if ($probe === $content && ! mb_check_encoding($probe, 'UTF-8')) {
            return false;
        }

        // U+FEFF, which a UTF-8 byte-order mark decodes to and which is not whitespace, so trimming
        // whitespace alone would leave it in front of the opening angle bracket.
        return self::opensWithSvgElement(ltrim($probe, "\u{FEFF}"));
    }

    /**
     * Whether the document's *root* element is an `svg`.
     *
     * The root, not the first mention. An HTML page containing an inline chart contains `<svg>`
     * too, and accepting it would let this API store a page and serve it back as an image — which,
     * for a document served from this origin, is the whole thing the format check exists to
     * prevent.
     */
    private static function opensWithSvgElement(string $head): bool
    {
        while (true) {
            $head = ltrim($head);

            if ($head === '' || $head[0] !== '<') {
                return false;
            }

            if (stripos($head, '<svg') === 0) {
                return true;
            }

            $skip = self::prologueLength($head);

            if ($skip <= 0) {
                return false;
            }

            $head = substr($head, $skip);
        }
    }

    /**
     * The length of the XML declaration, doctype or comment at the start of the string, or -1 for
     * anything else — which is what makes the walk above stop at the first real element rather than
     * search the whole document for one.
     */
    private static function prologueLength(string $head): int
    {
        if (str_starts_with($head, '<!--')) {
            $end = strpos($head, '-->');

            return $end === false ? -1 : $end + 3;
        }

        if (stripos($head, '<?xml') === 0 || stripos($head, '<!DOCTYPE') === 0) {
            $end = strpos($head, '>');

            return $end === false ? -1 : $end + 1;
        }

        return -1;
    }
}
