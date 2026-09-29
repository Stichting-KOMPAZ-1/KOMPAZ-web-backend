<?php

declare(strict_types=1);

namespace App\Http\Controllers\Nova;

use App\Exceptions\NotFoundException;
use App\Models\ContentBlock;
use App\Support\Files\ServedFile;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * A content block's picture, for the step form's thumbnail.
 *
 * Addressed by the block alone, unlike the API's, which nests it under its course because that is
 * where a reader's permission comes from. Nobody reaches the panel but a platform administrator,
 * who may read every course there is, so there is no parent here to check it against.
 */
final readonly class NovaContentBlockFileController
{
    public function show(ContentBlock $block): Response
    {
        $file = $block->file();

        if ($file === null) {
            throw new NotFoundException('Dit blok heeft geen bestand.');
        }

        $served = ServedFile::for($file);

        return response($served->content, HttpResponse::HTTP_OK, $served->headers());
    }
}
