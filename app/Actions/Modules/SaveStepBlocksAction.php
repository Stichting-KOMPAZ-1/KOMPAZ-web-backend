<?php

declare(strict_types=1);

namespace App\Actions\Modules;

use App\Actions\Videos\ApplyVideoSourceAction;
use App\Enums\ContentBlockType;
use App\Models\BlockInstruction;
use App\Models\ContentBlock;
use App\Models\Step;
use App\Models\User;
use App\Support\Access\ModuleAccess;
use App\Support\Files\StoredImage;
use App\Support\Modules\BlockCheckboxes;
use App\Support\Modules\BlockDetails;
use App\Support\Modules\InstructionDetails;
use App\Support\Modules\ModuleMessages;
use App\Support\Modules\OrderedRows;
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
 *    the field it should have come in. A video block is the same, with a link or an upload
 *    ({@see ApplyVideoSourceAction}). A table block without a table, and a checkbox block without
 *    options, are refused the same way.
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
            ContentBlockType::Instructions => $this->writeInstructionColumns($block, $details),
        };

        $block->save();

        // After the save, and only for the one kind whose content is rows: the instructions need a
        // block with a key to hang off. Everything the block itself could refuse was refused above,
        // so what is written here is a list already known to be acceptable.
        if ($details->type === ContentBlockType::Instructions) {
            $this->writeInstructions($block, $details);
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
     */
    private function writeCheckboxes(ContentBlock $block, BlockDetails $details): void
    {
        if ($details->checkboxes === null) {
            throw ValidationException::withMessages([$details->checkboxesField => ModuleMessages::CHECKBOXES_NEED_AN_OPTION]);
        }

        $block->checkbox_options = $details->checkboxes;
        $block->table_content = null;
        $block->body = null;
        $block->video_url = null;
        $block->file_storage_key = null;
        $block->file_content_type = null;
        $block->file_byte_count = null;
    }

    /**
     * A "Stap" block holds nothing itself (KOM-99) — its content is the instruction rows. What it
     * cannot be without is at least one of them, refused here rather than by the row's constraint,
     * which cannot count.
     */
    private function writeInstructionColumns(ContentBlock $block, BlockDetails $details): void
    {
        if ($details->instructions === []) {
            throw ValidationException::withMessages([$details->instructionsField => ModuleMessages::BLOCK_NEEDS_AN_INSTRUCTION]);
        }

        $block->body = null;
        $block->table_content = null;
        $block->checkbox_options = null;
        $block->video_url = null;
        $block->file_storage_key = null;
        $block->file_content_type = null;
        $block->file_byte_count = null;
    }

    /**
     * The instructions themselves, in the order they were sent, which is the order they are
     * numbered.
     *
     * {@see OrderedRows} matches a row by its key among this block's own instructions, writes the
     * rest as new ones and deletes what the list leaves out — through the model, so an instruction
     * taken away lets go of its picture (rule 24).
     *
     * A picture is kept unless a new one is sent, as a picture block's is, and `removeImage` is how
     * one is taken away on purpose. An instruction with no text is refused under its own field: the
     * row requires a body, and a save the database refuses is a failure the operator did not cause
     * and could not read.
     */
    private function writeInstructions(ContentBlock $block, BlockDetails $details): void
    {
        OrderedRows::write(
            $block->instructions(),
            $details->instructions,
            static fn (InstructionDetails $instruction): ?string => $instruction->id,
            function (BlockInstruction $row, InstructionDetails $instruction): void {
                if ($instruction->body === null || trim(strip_tags($instruction->body)) === '') {
                    throw ValidationException::withMessages([
                        $instruction->bodyField => ModuleMessages::INSTRUCTION_NEEDS_BODY,
                    ]);
                }

                $row->title = self::blankToNull($instruction->title);
                $row->body = $instruction->body;

                if ($instruction->image !== null) {
                    if (! $row->exists) {
                        $row->setAttribute('id', $row->newUniqueId());
                    }

                    $row->applyFile(StoredImage::store($instruction->image, BlockInstruction::FILE_PREFIX, (string) $row->getKey()));

                    return;
                }

                if ($instruction->removeImage) {
                    $row->clearFile();
                }
            },
        );
    }

    private static function blankToNull(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : trim($value);
    }
}
