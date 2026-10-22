<?php

declare(strict_types=1);

namespace App\Http\Controllers\Nova;

use App\Exceptions\NotFoundException;
use App\Models\CarouselSlide;
use App\Support\Files\ServedFile;

/**
 * One carousel slide's picture, for the step form's thumbnail (KOM-100).
 *
 * Addressed by the slide alone, as a block's picture is, and for the same reason: nobody reaches
 * the panel but an operator who may read every course there is, so there is no parent here to check
 * it against. The API's address nests it under its course, which is where a reader's permission
 * comes from.
 */
final readonly class NovaCarouselSlideImageController
{
    public function show(CarouselSlide $slide): ServedFile
    {
        $file = $slide->file();

        if ($file === null) {
            throw new NotFoundException('Deze slide heeft geen afbeelding.');
        }

        return ServedFile::for($file);
    }
}
