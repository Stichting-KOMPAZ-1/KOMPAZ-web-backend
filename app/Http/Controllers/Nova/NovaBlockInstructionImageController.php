<?php

declare(strict_types=1);

namespace App\Http\Controllers\Nova;

use App\Exceptions\NotFoundException;
use App\Models\BlockInstruction;
use App\Support\Files\ServedFile;

/**
 * One instruction's picture, for the step form's thumbnail (KOM-99).
 *
 * Addressed by the instruction alone, as a block's picture is, and for the same reason: nobody
 * reaches the panel but an operator who may read every course there is, so there is no parent here
 * to check it against. The API's address nests it under its course, which is where a reader's
 * permission comes from.
 */
final readonly class NovaBlockInstructionImageController
{
    public function show(BlockInstruction $instruction): ServedFile
    {
        $file = $instruction->file();

        if ($file === null) {
            throw new NotFoundException('Deze stap heeft geen afbeelding.');
        }

        return ServedFile::for($file);
    }
}
