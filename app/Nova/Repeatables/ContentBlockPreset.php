<?php

declare(strict_types=1);

namespace App\Nova\Repeatables;

use App\Actions\Modules\SaveStepBlocksAction;
use App\Models\ContentBlock;
use App\Models\Step;
use App\Models\User;
use App\Nova\Fields\VideoUpload;
use App\Support\Modules\BlockCheckboxes;
use App\Support\Modules\BlockDetails;
use App\Support\Modules\BlockTable;
use App\Support\Modules\InstructionDetails;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use JsonException;
use Laravel\Nova\Fields\Repeater\Presets\HasMany;
use Laravel\Nova\Fields\Repeater\Presets\Preset;
use Laravel\Nova\Fields\Repeater\RepeatableCollection;
use Laravel\Nova\Http\Requests\NovaRequest;
use LogicException;

/**
 * Reads and writes a step's blocks for the step form.
 *
 * Nova's own {@see HasMany} preset does not fit, for two reasons. It picks a row's repeatable by
 * model class, and all three kinds of block are one model. And it removes rows with a query
 * delete, which Eloquent never sees: a picture block taken off a step would leave its bytes on the
 * disk with nothing left that knows where they are (rule 24).
 *
 * Writing is not done here at all: the rows are read into {@see BlockDetails} and handed to
 * {@see SaveStepBlocksAction}, which the API's step endpoints call too, so a block is matched,
 * kept, refused and discarded the same way whichever door it came through. The order of the rows
 * is the order of the blocks; Nova's arrows move rows in the browser.
 */
final class ContentBlockPreset implements Preset
{
    public function set(
        NovaRequest $request,
        string $requestAttribute,
        $model,
        string $attribute,
        RepeatableCollection $repeatables,
        string|int|null $uniqueField,
    ): callable {
        return function () use ($request, $requestAttribute, $model, $repeatables): void {
            if (! $model instanceof Step) {
                throw new LogicException('Content blocks are written onto a step and nothing else.');
            }

            $operator = $request->user();

            if (! $operator instanceof User) {
                throw new LogicException('A step form was saved without an operator.');
            }

            $rows = $request->input($requestAttribute);
            $blocks = [];

            foreach (is_array($rows) ? $rows : [] as $index => $row) {
                $repeatable = $repeatables->findByKey(is_array($row) ? ($row['type'] ?? null) : null);

                if (! $repeatable instanceof ContentBlockRepeatable) {
                    throw new LogicException('A step form row names a kind of block this form does not offer.');
                }

                $fields = "{$requestAttribute}.{$index}.fields";
                $image = $request->file("{$fields}.file_storage_key");

                $blocks[] = new BlockDetails(
                    type: $repeatable::type(),
                    imageField: "{$fields}.file_storage_key",
                    videoUrlField: "{$fields}.video_url",
                    videoUploadField: "{$fields}.".VideoUpload::ATTRIBUTE,
                    tableField: "{$fields}.table_content",
                    checkboxesField: "{$fields}.checkbox_options",
                    instructionsField: "{$fields}.instructions",
                    title: self::text($request, "{$fields}.title"),
                    body: self::text($request, "{$fields}.body"),
                    videoUrl: self::text($request, "{$fields}.video_url"),
                    videoUploadId: self::text($request, "{$fields}.".VideoUpload::ATTRIBUTE),
                    image: $image instanceof UploadedFile ? $image : null,
                    table: BlockTable::fromInput($request->input("{$fields}.table_content")),
                    checkboxes: BlockCheckboxes::fromInput($request->input("{$fields}.checkbox_options")),
                    instructions: self::instructions($request, $fields),
                    id: self::text($request, "{$fields}.".ContentBlockRepeatable::KEY_FIELD),
                );
            }

            app(SaveStepBlocksAction::class)->execute($operator, $model, $blocks);
        };
    }

    /**
     * A "Stap" block's instructions as its editor sends them (KOM-99).
     *
     * The list itself is JSON, because Nova's repeater cannot nest a repeating list inside a row
     * and the component that stands in for one sends a string. A picture cannot travel inside that
     * string, so each is sent beside it as a file, at `instructions_images.{position}` — the
     * component's own attribute with `_images` after it, so neither side has to take the other's
     * path apart. Read here off the request, the way a picture block's own is.
     *
     * @return list<InstructionDetails>
     */
    private static function instructions(NovaRequest $request, string $fields): array
    {
        $sent = $request->input("{$fields}.instructions");

        if (is_string($sent)) {
            try {
                $sent = json_decode($sent, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return [];
            }
        }

        $instructions = [];

        foreach (is_array($sent) ? $sent : [] as $position => $instruction) {
            if (! is_array($instruction)) {
                continue;
            }

            $imageField = "{$fields}.instructions_images.{$position}";
            $image = $request->file($imageField);

            $instructions[] = new InstructionDetails(
                imageField: $imageField,
                // The text has no input of its own here — it is inside the editor's JSON — so a
                // refusal about it goes under the editor, which is what the dialog can show.
                bodyField: "{$fields}.instructions",
                title: self::textOf($instruction, 'title'),
                body: self::textOf($instruction, 'body'),
                image: $image instanceof UploadedFile ? $image : null,
                removeImage: filter_var($instruction['removeImage'] ?? false, FILTER_VALIDATE_BOOL),
                id: self::textOf($instruction, 'id'),
            );
        }

        return $instructions;
    }

    /**
     * One value out of a decoded instruction, or null when it was left empty.
     *
     * @param  array<mixed>  $instruction
     */
    private static function textOf(array $instruction, string $key): ?string
    {
        $value = $instruction[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /** A text field from the form, or null when it was left empty. */
    private static function text(NovaRequest $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return Collection<int, ContentBlockRepeatable> */
    public function get(NovaRequest $request, $model, string $attribute, RepeatableCollection $repeatables): Collection
    {
        if (! $model instanceof Step || ! $model->exists) {
            return RepeatableCollection::make();
        }

        return RepeatableCollection::make($model->blocks)
            ->map(function (ContentBlock $block) use ($repeatables): ContentBlockRepeatable {
                $repeatable = $repeatables->first(
                    fn (mixed $candidate): bool => $candidate instanceof ContentBlockRepeatable
                        && $candidate::type() === $block->type,
                );

                if (! $repeatable instanceof ContentBlockRepeatable) {
                    throw new LogicException("The step form offers no row for a {$block->type->value} block.");
                }

                return new $repeatable($block);
            });
    }
}
