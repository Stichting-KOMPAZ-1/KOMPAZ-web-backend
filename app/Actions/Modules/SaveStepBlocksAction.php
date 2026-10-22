<?php

declare(strict_types=1);

namespace App\Actions\Modules;

use App\Actions\Videos\ApplyVideoSourceAction;
use App\Enums\ContentBlockType;
use App\Models\CarouselSlide;
use App\Models\ContentBlock;
use App\Models\Step;
use App\Models\User;
use App\Support\Access\ModuleAccess;
use App\Support\Files\StoredImage;
use App\Support\Modules\BlockCheckboxes;
use App\Support\Modules\BlockDetails;
use App\Support\Modules\ModuleMessages;
use App\Support\Modules\OrderedRows;
use App\Support\Modules\SlideDetails;
use Illuminate\Validation\ValidationException;

/**
 * Makes a step's blocks the list given, in its order.
 *
 * The one implementation for the panel's step form and the API, because it is where the rules a
 * block's row cannot state for itself live:
 *
 *  - **A block is matched by its key among this step's own blocks, and only if it is still the
 *    same kind.** A key from another step, or a picture re-sent as a text, is a new block.
 *  - **A picture block keeps its picture unless it is sent a new one**, so an edit need not
 *    upload every picture again â€” and a new picture block with none is refused, in Dutch, under
 *    the field it should have come in. A video block is the same, with a link or an upload
 *    ({@see ApplyVideoSourceAction}). A table block without a table, a checkbox block without
 *    options and a "Stap" block without a title are refused the same way.
 *  - **Only the fields its kind uses are written**, and the rest cleared, so the table's check
 *    constraints never see a row they would refuse (rule 23).
 *  - **A removed block is a model delete**, which lets go of its file (rule 24).
 */
final readonly class SaveStepBlocksAction
{
    public function __construct(private ApplyVideoSourceAction $videoSource) {}

    /** @param  list<BlockDetails>  $blocks */
    public function execute(User $actor, Step $step, array $blocks): void
    {
        ModuleAccess::ensureCanManageContent($actor);

        $existing = $step->blocks()->get()->keyBy(fn (ContentBlock $block): string => (string) $block->getKey());
        $kept = [];
        $planned = [];

        foreach ($blocks as $details) {
            $block = $details->id !== null && ! isset($kept[$details->id]) ? $existing->get($details->id) : null;

            if ($block === null || $block->type !== $details->type) {
                $block = new ContentBlock(['step_id' => $step->getKey(), 'type' => $details->type]);
                $block->setAttribute('id', $block->newUniqueId());
            } else {
                $kept[(string) $block->getKey()] = true;
            }

            $planned[] = [$block, $details];
        }

        foreach ($existing as $key => $block) {
            if (! isset($kept[$key])) {
                $block->delete();
            }
        }

        foreach ($planned as $position => [$block, $details]) {
            $this->write($actor, $block, $details, $position);
        }
    }

    private function write(User $actor, ContentBlock $block, BlockDetails $details, int $position): void
    {
        $block->title = self::blankToNull($details->title);
        $block->position = $position;

        match ($details->type) {
            ContentBlockType::Text => $this->writeText($block, $details),
            ContentBlockType::Image => $this->writeImage($block, $details),
            ContentBlockType::Video => $this->writeVideo($actor, $block, $details),
            ContentBlockType::Table => $this->writeTable($block, $details),
            ContentBlockType::Checkboxes => $this->writeCheckboxes($block, $details),
            ContentBlockType::Instruction => $this->writeInstruction($block, $details),
            ContentBlockType::Carousel => $this->writeCarousel($block, $details),
        };

        $block->save();

        // After the save, and only for the kind whose content is rows: the slides need a block with
        // a key to hang off. Everything they could be refused for was refused above.
        if ($details->type === ContentBlockType::Carousel) {
            $this->writeSlides($block, $details);
        }
    }

    private function writeText(ContentBlock $block, BlockDetails $details): void
    {
        $block->body = $details->body ?? '';
        $block->table_content = null;
        $block->checkbox_options = null;
        $block->video_url = null;
        $block->file_storage_key = null;
        $block->file_content_type = null;
        $block->file_byte_count = null;
    }

    private function writeImage(ContentBlock $block, BlockDetails $details): void
    {
        $block->body = null;
        $block->table_content = null;
        $block->checkbox_options = null;

        if ($details->image !== null) {
            $block->applyFile(StoredImage::store($details->image, ContentBlock::FILE_PREFIX, (string) $block->getKey()));

            return;
        }

        if ($block->file() === null) {
            throw ValidationException::withMessages([$details->imageField => ModuleMessages::BLOCK_NEEDS_IMAGE]);
        }

        $block->video_url = null;
    }

    private function writeVideo(User $actor, ContentBlock $block, BlockDetails $details): void
    {
        $block->body = null;
        $block->table_content = null;
        $block->checkbox_options = null;

        $this->videoSource->execute(
            $actor,
            $block,
            $details->videoUrl,
            $details->videoUploadId,
            $details->videoUrlField,
            $details->videoUploadField,
        );
    }

    /**
     * A table block's table, which it cannot be without. Refused in Dutch under the field it should
     * have come in, as a picture block without a picture is.
     */
    private function writeTable(ContentBlock $block, BlockDetails $details): void
    {
        if ($details->table === null) {
            throw ValidationException::withMessages([$details->tableField => ModuleMessages::TABLE_NEEDS_A_COLUMN]);
        }

        $block->table_content = $details->table;
        $block->checkbox_options = null;
        $block->body = null;
        $block->video_url = null;
        $block->file_storage_key = null;
        $block->file_content_type = null;
        $block->file_byte_count = null;
    }

    /**
     * A checkbox block's options, which it cannot be without. Refused in Dutch under the field they
     * should have come in, as a table block's table is. Blank options were already dropped by
     * {@see BlockCheckboxes}, so a set typed as empty rows arrives here as none at all.
     *
     * An option keeps its identifier when it is sent back with one this block already has, and a
     * reader's ticks with it; anything else is a new option.
     */
    private function writeCheckboxes(ContentBlock $block, BlockDetails $details): void
    {
        if ($details->checkboxes === null) {
            throw ValidationException::withMessages([$details->checkboxesField => ModuleMessages::CHECKBOXES_NEED_AN_OPTION]);
        }

        $block->checkbox_options = $details->checkboxes->keepingIdsOf($block->checkbox_options);
        $block->table_content = null;
        $block->body = self::optionalMarkup($details->body);
        $block->video_url = null;
        $block->file_storage_key = null;
        $block->file_content_type = null;
        $block->file_byte_count = null;
    }

    /**
     * One step of a sequence (KOM-99): a title it cannot be without, and a body and a picture it
     * may have. The picture is kept unless a new one is sent, as a picture block's is, and
     * `removeImage` is how one is taken away on purpose.
     */
    private function writeInstruction(ContentBlock $block, BlockDetails $details): void
    {
        if ($block->title === null) {
            throw ValidationException::withMessages([$details->titleField => ModuleMessages::INSTRUCTION_NEEDS_TITLE]);
        }

        $block->body = self::optionalMarkup($details->body);
        $block->table_content = null;
        $block->checkbox_options = null;
        $block->video_url = null;

        if ($details->image !== null) {
            $block->applyFile(StoredImage::store($details->image, ContentBlock::FILE_PREFIX, (string) $block->getKey()));

            return;
        }

        if ($details->removeImage) {
            $block->file_storage_key = null;
            $block->file_content_type = null;
            $block->file_byte_count = null;
        }
    }

    /**
     * A body that may be left out, null when there is no text in it. The panel's editor sends
     * `<div><br></div>` for a field nobody typed in, which survives cleaning but shows nothing.
     */
    private static function optionalMarkup(?string $body): ?string
    {
        return $body === null || trim(strip_tags($body)) === '' ? null : $body;
    }

    /**
     * A carousel holds nothing itself (KOM-100): its content is its slides, and what it cannot be
     * without is at least one of them.
     *
     * Every slide is judged here, before anything is written: a title, and a picture — sent now, or
     * already on a slide of this carousel that the list names. Refusing the fifth slide after the
     * first four had stored their pictures would leave those files behind with no row (rule 13).
     */
    private function writeCarousel(ContentBlock $block, BlockDetails $details): void
    {
        if ($details->slides === []) {
            throw ValidationException::withMessages([$details->slidesField => ModuleMessages::CAROUSEL_NEEDS_A_SLIDE]);
        }

        $existing = $block->exists
            ? array_flip(array_map('strval', $block->slides()->pluck('id')->all()))
            : [];
        $named = [];

        foreach ($details->slides as $index => $slide) {
            $number = $index + 1;

            if (self::blankToNull($slide->title) === null) {
                throw ValidationException::withMessages([$slide->titleField => ModuleMessages::slideNeedsTitle($number)]);
            }

            // A key names one of this carousel's slides once; sent again, it is a new slide.
            $keepsPicture = $slide->id !== null && isset($existing[$slide->id]) && ! isset($named[$slide->id]);

            if ($keepsPicture) {
                $named[$slide->id] = true;
            }

            if ($slide->image === null && ! $keepsPicture) {
                throw ValidationException::withMessages([$slide->imageField => ModuleMessages::slideNeedsImage($number)]);
            }
        }

        $block->title = null;
        $block->body = null;
        $block->table_content = null;
        $block->checkbox_options = null;
        $block->video_url = null;
        $block->file_storage_key = null;
        $block->file_content_type = null;
        $block->file_byte_count = null;
    }

    /**
     * The slides themselves, in the order they were sent, which is the order a reader pages through.
     *
     * {@see OrderedRows} matches a slide by its key among this carousel's own, writes the rest as
     * new ones and deletes what the list leaves out — through the model, so a slide taken away lets
     * go of its picture (rule 24). A picture is kept unless a new one is sent.
     */
    private function writeSlides(ContentBlock $block, BlockDetails $details): void
    {
        OrderedRows::write(
            $block->slides(),
            $details->slides,
            static fn (SlideDetails $slide): ?string => $slide->id,
            static function (CarouselSlide $row, SlideDetails $slide): void {
                $row->title = trim((string) $slide->title);
                $row->body = self::optionalMarkup($slide->body);

                if ($slide->image !== null) {
                    if (! $row->exists) {
                        $row->setAttribute('id', $row->newUniqueId());
                    }

                    $row->applyFile(StoredImage::store($slide->image, CarouselSlide::FILE_PREFIX, (string) $row->getKey()));
                }
            },
        );
    }

    private static function blankToNull(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : trim($value);
    }
}
