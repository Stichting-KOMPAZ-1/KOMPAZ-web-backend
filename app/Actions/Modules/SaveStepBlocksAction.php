<?php

declare(strict_types=1);

namespace App\Actions\Modules;

use App\Enums\ContentBlockType;
use App\Models\ContentBlock;
use App\Models\Step;
use App\Models\User;
use App\Support\Access\ModuleAccess;
use App\Support\Files\StoredImage;
use App\Support\Modules\BlockDetails;
use App\Support\Modules\ModuleMessages;
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
 *    upload every picture again — and a new picture block with none is refused, in Dutch, under
 *    the field it should have come in.
 *  - **Only the fields its kind uses are written**, and the rest cleared, so the table's check
 *    constraints never see a row they would refuse (rule 23).
 *  - **A removed block is a model delete**, which lets go of its file (rule 24).
 */
final readonly class SaveStepBlocksAction
{
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
            $this->write($block, $details, $position);
        }
    }

    private function write(ContentBlock $block, BlockDetails $details, int $position): void
    {
        $block->title = self::blankToNull($details->title);
        $block->position = $position;

        match ($details->type) {
            ContentBlockType::Text => $this->writeText($block, $details),
            ContentBlockType::Image => $this->writeImage($block, $details),
            ContentBlockType::Video => $this->writeVideo($block, $details),
        };

        $block->save();
    }

    private function writeText(ContentBlock $block, BlockDetails $details): void
    {
        $block->body = $details->body ?? '';
        $block->video_url = null;
        $block->file_storage_key = null;
        $block->file_content_type = null;
        $block->file_byte_count = null;
    }

    private function writeImage(ContentBlock $block, BlockDetails $details): void
    {
        $block->body = null;

        if ($details->image !== null) {
            $block->applyFile(StoredImage::store($details->image, ContentBlock::FILE_PREFIX, (string) $block->getKey()));

            return;
        }

        if ($block->file() === null) {
            throw ValidationException::withMessages([$details->imageField => ModuleMessages::BLOCK_NEEDS_IMAGE]);
        }

        $block->video_url = null;
    }

    private function writeVideo(ContentBlock $block, BlockDetails $details): void
    {
        $block->body = null;
        $block->applyVideoUrl($details->videoUrl ?? '');
    }

    private static function blankToNull(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : trim($value);
    }
}
