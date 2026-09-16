<?php

declare(strict_types=1);

namespace App\Support\Images;

use RuntimeException;

/**
 * The image served for an organization that has not uploaded one.
 *
 * It lives in the repository rather than in the database, because it is the same bytes for every
 * organization and nothing may change it: a placeholder row would be one more thing a deployment
 * has to have and a caller could overwrite. Serving it from the logo endpoint rather than leaving
 * the fallback to each client means every client agrees about what an organization without a logo
 * looks like, and none of them ships a second copy of the image.
 *
 * The file is a stand-in mark, not the brand asset. Replacing it is the whole change.
 */
final class PlaceholderLogo
{
    private static ?string $content = null;

    public static function contentType(): string
    {
        return LogoImage::SVG;
    }

    /**
     * The placeholder's bytes. Read once and held: it is small, and every organization without a
     * logo asks for it.
     *
     * A missing file means the deployment shipped without it, which is a mistake to hear about
     * loudly rather than to paper over with an empty response that renders as a broken image.
     */
    public static function content(): string
    {
        if (self::$content !== null) {
            return self::$content;
        }

        $path = resource_path('assets/placeholder-logo.svg');
        $content = is_readable($path) ? file_get_contents($path) : false;

        if ($content === false) {
            throw new RuntimeException("The placeholder logo is missing from the deployment: {$path}");
        }

        return self::$content = $content;
    }
}
