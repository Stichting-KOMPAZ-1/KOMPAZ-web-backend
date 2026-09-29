<?php

declare(strict_types=1);

namespace App\Models\Contracts;

use App\Support\Files\StoredFile;

/**
 * A row that shows one video, as a link or as an upload and never both.
 *
 * A module's video and a step's video block. Both tables state the "one or the other" rule as a
 * check constraint, and both models clear the other half whenever one is set, so a save never
 * reaches a row the database would refuse (rule 23).
 */
interface HoldsVideo
{
    /** The uploaded file, when it is one. */
    public function file(): ?StoredFile;

    /** Points the row at an upload, clearing any link. */
    public function applyFile(StoredFile $file): void;

    /** Points the row at a link, clearing any upload. */
    public function applyVideoUrl(string $url): void;
}
