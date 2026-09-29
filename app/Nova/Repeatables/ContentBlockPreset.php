<?php

declare(strict_types=1);

namespace App\Nova\Repeatables;

use App\Actions\Modules\SaveStepBlocksAction;
use App\Models\ContentBlock;
use App\Models\Step;
use App\Models\User;
use App\Support\Modules\BlockDetails;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
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
                    title: self::text($request, "{$fields}.title"),
                    body: self::text($request, "{$fields}.body"),
                    videoUrl: self::text($request, "{$fields}.video_url"),
                    image: $image instanceof UploadedFile ? $image : null,
                    id: self::text($request, "{$fields}.".ContentBlockRepeatable::KEY_FIELD),
                );
            }

            app(SaveStepBlocksAction::class)->execute($operator, $model, $blocks);
        };
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
