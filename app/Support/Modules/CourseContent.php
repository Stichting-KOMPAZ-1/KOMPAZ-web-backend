<?php

declare(strict_types=1);

namespace App\Support\Modules;

use App\Enums\ContentBlockType;
use App\Exceptions\NotFoundException;
use App\Models\BlockInstruction;
use App\Models\ContentBlock;
use App\Models\ELearning;
use App\Models\Step;
use App\Support\Files\ServedFile;
use App\Support\Videos\VideoPlayback;

/**
 * What lies inside a course, reached through the course in the address.
 *
 * Both doors a course is read through — a signed-in reader's and a shared link's — grant permission
 * for the course and nothing else, so a part or a block named under it has to be one of that
 * course's. Otherwise any course a caller may read would be a key to every part on the platform.
 */
final class CourseContent
{
    /** Refuses a part that belongs to a different course. */
    public static function ensurePartBelongsToCourse(ELearning $eLearning, Step $part): void
    {
        if ($eLearning->chapters()->whereKey($part->chapter_id)->exists()) {
            return;
        }

        throw new NotFoundException('Dit onderdeel hoort niet bij deze e-learning.');
    }

    /**
     * The bytes behind a picture block, or a player sent on to an uploaded video block.
     *
     * A video is a redirect to a short-lived, read-only link, for the reason a module's is; a
     * picture is small enough to hand back directly. The part is the caller's to have checked.
     */
    public static function blockFile(Step $part, ContentBlock $block): ServedFile|VideoPlayback
    {
        if ($block->step_id !== $part->getKey()) {
            throw new NotFoundException('Dit blok hoort niet bij dit onderdeel.');
        }

        $file = $block->file();

        if ($file === null) {
            throw new NotFoundException('Dit blok heeft geen bestand.');
        }

        return $block->type === ContentBlockType::Video
            ? VideoPlayback::for($file)
            : ServedFile::for($file);
    }

    /**
     * The bytes behind one instruction's picture (KOM-99).
     *
     * Addressed through its block, as a block is through its part: an instruction is never found by
     * its own key alone, so one named under the wrong block is not found rather than served.
     */
    public static function instructionFile(Step $part, ContentBlock $block, BlockInstruction $instruction): ServedFile
    {
        if ($block->step_id !== $part->getKey()) {
            throw new NotFoundException('Dit blok hoort niet bij dit onderdeel.');
        }

        if ($instruction->content_block_id !== $block->getKey()) {
            throw new NotFoundException('Deze stap hoort niet bij dit blok.');
        }

        $file = $instruction->file();

        if ($file === null) {
            throw new NotFoundException('Deze stap heeft geen afbeelding.');
        }

        return ServedFile::for($file);
    }
}
