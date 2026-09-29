<?php

declare(strict_types=1);

namespace App\Nova\Repeatables;

use App\Enums\ContentBlockType;
use App\Models\ContentBlock;
use App\Models\Step;
use App\Support\Modules\ModuleMessages;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
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
 * disk with nothing left that knows where they are (rule 24). Here every removal is a model delete,
 * so {@see ContentBlock}'s own discard runs for each one.
 *
 * The order of the rows is the order of the blocks. Nova's arrows move rows in the browser, and
 * the position written here is the row's place in what was submitted.
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

            $rows = $request->input($requestAttribute);
            $rows = is_array($rows) ? $rows : [];

            $existing = $model->blocks()->get()->keyBy(fn (ContentBlock $block): string => (string) $block->getKey());

            $planned = [];
            $kept = [];

            foreach ($rows as $index => $row) {
                $repeatable = $repeatables->findByKey(is_array($row) ? ($row['type'] ?? null) : null);

                if (! $repeatable instanceof ContentBlockRepeatable) {
                    throw new LogicException('A step form row names a kind of block this form does not offer.');
                }

                $type = $repeatable::type();
                $key = is_array($row) ? ($row['fields'][ContentBlockRepeatable::KEY_FIELD] ?? null) : null;
                $block = is_string($key) ? $existing->get($key) : null;

                // A key that is not one of this step's blocks, names a block of another kind or was
                // already claimed by an earlier row is a new block. The hidden field is the browser's
                // to send, so what it says is only ever looked up among this step's own rows.
                if ($block === null || $block->type !== $type || isset($kept[$key])) {
                    $block = new ContentBlock(['step_id' => $model->getKey(), 'type' => $type]);
                } else {
                    $kept[$key] = true;
                }

                $planned[] = [(string) $index, $block, $repeatable];
            }

            // Before the writes, so a block moved into the place of a removed one never shares it.
            $existing
                ->reject(fn (ContentBlock $block): bool => isset($kept[(string) $block->getKey()]))
                ->each(fn (ContentBlock $block): ?bool => $block->delete());

            foreach ($planned as $position => [$index, $block, $repeatable]) {
                $this->write($request, "{$requestAttribute}.{$index}.fields", $block, $repeatable, $position);
            }
        };
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

    /** Fills one row into its block and saves it at its place in the step. */
    private function write(
        NovaRequest $request,
        string $fieldsAttribute,
        ContentBlock $block,
        ContentBlockRepeatable $repeatable,
        int $position,
    ): void {
        $callbacks = [];

        foreach ($repeatable->fields($request) as $field) {
            // The key is how the row was matched, not something it gets to set.
            if ($field->attribute === ContentBlockRepeatable::KEY_FIELD) {
                continue;
            }

            $callback = $field->fillInto($request, $block, $field->attribute, "{$fieldsAttribute}.{$field->attribute}");

            if (is_callable($callback)) {
                $callbacks[] = $callback;
            }
        }

        // A link replaces an uploaded video, if an earlier one was: a block pointing at both is
        // refused by the database, and the file it stops pointing at is discarded on save.
        if ($block->type === ContentBlockType::Video && is_string($block->video_url)) {
            $block->applyVideoUrl($block->video_url);
        }

        if ($block->type === ContentBlockType::Image && $block->file_storage_key === null) {
            throw ValidationException::withMessages([
                "{$fieldsAttribute}.file_storage_key" => ModuleMessages::BLOCK_NEEDS_IMAGE,
            ]);
        }

        $block->position = $position;
        $block->save();

        foreach ($callbacks as $callback) {
            $callback();
        }
    }
}
