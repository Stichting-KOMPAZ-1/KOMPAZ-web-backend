<?php

declare(strict_types=1);

namespace App\Support\Files;

use App\Support\Images\AcceptableLogo;
use App\Support\Images\LogoImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use LogicException;

/**
 * Writes an uploaded picture to the disk the way rule 12 requires, for every content record that
 * carries one — whether the panel's form or the API brought it.
 *
 * **The media type is read out of the bytes**, never taken from the upload's own `Content-Type`
 * or its file name: the stored value is what a later response is labelled with. The key is minted
 * from the owner's identifier and a fresh one, never accepted from a caller.
 *
 * The bytes are written *before* the row that will point at them, which is rule 13's ordering: a
 * failure between the two leaves an orphaned file rather than a row pointing at nothing. What the
 * caller does with the returned {@see StoredFile} is apply it to its row and save.
 */
final class StoredImage
{
    /**
     * @param  string  $prefix  where this kind of record keeps its pictures, under the disk's root
     * @param  string  $ownerId  the record the picture belongs to, which its key is filed under
     */
    public static function store(UploadedFile $upload, string $prefix, string $ownerId): StoredFile
    {
        $contents = (string) file_get_contents($upload->getRealPath());
        $contentType = LogoImage::detectContentType($contents);

        // Refused already by AcceptableLogo, which every form carrying a picture asks first and
        // which answers in Dutch under the field. Reaching here with unrecognized bytes is a
        // defect, not a bad upload.
        if ($contentType === null) {
            throw new LogicException('Unrecognized image content reached storage past its validation ('.AcceptableLogo::class.').');
        }

        $key = StoredFile::mintKey($prefix, $ownerId, 'image', LogoImage::extensionFor($contentType));

        Storage::put($key, $contents);

        return new StoredFile($key, $contentType, strlen($contents));
    }
}
