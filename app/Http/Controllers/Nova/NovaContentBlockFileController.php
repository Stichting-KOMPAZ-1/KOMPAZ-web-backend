<?php

declare(strict_types=1);

namespace App\Http\Controllers\Nova;

use App\Exceptions\NotFoundException;
use App\Models\ContentBlock;
use App\Support\Files\ServedFile;

/**
 * A content block's picture, for the step form's thumbnail.
 *
 * Addressed by the block alone, unlike the API's, which nests it under its course because that is
 * where a reader's permission comes from. Nobody reaches the panel but a platform administrator,
 * who may read every course there is, so there is no parent here to check it against.
 */
final readonly class NovaContentBlockFileController
{
    public function show(ContentBlock $block): ServedFile
    {
        $file = $block->file();

        if ($file === null) {
            throw new NotFoundException('Dit blok heeft geen bestand.');
        }

        return ServedFile::for($file);
    }
}
