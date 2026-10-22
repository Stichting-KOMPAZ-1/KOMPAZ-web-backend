<?php

declare(strict_types=1);

namespace App\Support\Modules;

use App\Enums\ContentBlockType;
use App\Exceptions\NotFoundException;
use App\Models\CarouselSlide;
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
     * Returns a carousel slide's picture, when the slide is on that block and the block on that
     * part (KOM-100). Each link of the address is checked against the one before it, for the reason
     * a block is checked against its part.
     */
    public static function slideFile(Step $part, ContentBlock $block, CarouselSlide $slide): ServedFile
    {
        if ($block->step_id !== $part->getKey()) {
            throw new NotFoundException('Dit blok hoort niet bij dit onderdeel.');
        }

        $file = $slide->content_block_id === $block->getKey() ? $slide->file() : null;

        if ($file === null) {
            throw new NotFoundException('Deze slide hoort niet bij dit blok.');
        }

        return ServedFile::for($file);
    }
}
