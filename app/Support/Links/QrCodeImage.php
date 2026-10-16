<?php

declare(strict_types=1);

namespace App\Support\Links;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Writer;

/**
 * A QR code as a PNG, for an organization to print (KOM-95).
 *
 * Drawn here rather than in the browser so that every download of a course's code for an
 * organization is the same image, and so the panel never has to know how one is made. GD, which
 * `composer.json` requires: the other back ends need Imagick, which nothing else here does.
 */
final class QrCodeImage
{
    /** Pixels along a side: sharp on an A4 poster, and a few kilobytes. */
    public const int SIZE = 1024;

    /**
     * The PNG bytes encoding an address.
     *
     * Medium error correction rather than the library's low default, because these codes are
     * printed, and paper gets creased and stained: M still scans with about a sixth of it gone.
     */
    public static function png(string $address): string
    {
        $writer = new Writer(new GDLibRenderer(self::SIZE, margin: 4, imageFormat: 'png'));

        return $writer->writeString($address, ecLevel: ErrorCorrectionLevel::M());
    }
}
