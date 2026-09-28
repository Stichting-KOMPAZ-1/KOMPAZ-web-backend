<?php

declare(strict_types=1);

namespace App\Support\Files;

use Illuminate\Support\Str;

/**
 * Where a file is, and what its bytes turned out to be.
 *
 * Three columns that are only ever true together, said once. A module, a course, a video and a
 * content block each hold one, and each of them stores it inline rather than in a table of its own:
 * unlike an organization's logo, none of these files is optional in a way that could leave the row
 * standing without it, so there is nothing for a separate row to express except an extra join.
 *
 * The media type is read out of the content and kept, never taken from the upload's own header or
 * its file name — it is what a later response is labelled with, so believing the caller would let
 * them choose how their bytes are handed back.
 */
final readonly class StoredFile
{
    public function __construct(
        public string $key,
        public string $contentType,
        public int $byteCount,
    ) {}

    /**
     * Mints a key for a file belonging to one record.
     *
     * A fresh identifier every time, so replacing a file writes a new one rather than overwriting
     * the one still being served: the old key stays valid until the new row is committed, and only
     * then is it discarded. Never accepted from a caller — the prefix and the owner's identifier
     * are ours, so nothing an upload says can reach another record's file.
     */
    public static function mintKey(string $prefix, string $ownerId, string $name, string $extension): string
    {
        return sprintf('%s/%s/%s-%s.%s', $prefix, $ownerId, $name, Str::orderedUuid()->getHex(), $extension);
    }
}
