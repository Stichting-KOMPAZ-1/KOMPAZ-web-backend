<?php

declare(strict_types=1);

namespace App\Nova\Repeatables;

use App\Actions\Modules\SaveModuleVideosAction;
use App\Models\Module;
use App\Models\ModuleActivation;
use App\Models\User;
use App\Nova\Fields\VideoUpload;
use App\Support\Modules\VideoDetails;
use Laravel\Nova\Fields\Repeater\Presets\HasMany;
use Laravel\Nova\Fields\Repeater\RepeatableCollection;
use Laravel\Nova\Http\Requests\NovaRequest;
use LogicException;

/**
 * Writes a module's "Video's" — the platform's or an organization's — for the panel's forms.
 *
 * Nova's own {@see HasMany} preset reads the rows perfectly well, so reading is left to it. Writing
 * is not: without a unique field it deletes every row with a query and inserts the list again, and
 * with one it still removes rows by query. Neither is seen by a model event, and an uploaded
 * video is a file whose bytes nothing would then let go of (rule 24) — and re-inserting would
 * drop the upload a row already had, since an edit does not send it again.
 *
 * So the rows are read into {@see VideoDetails} and handed to {@see SaveModuleVideosAction}, which
 * the API's module saves call too: an upload is claimed, and a video is matched, kept and let go
 * of the same way whichever door it came through.
 */
final class ModuleVideoPreset extends HasMany
{
    #[\Override]
    public function set(
        NovaRequest $request,
        string $requestAttribute,
        $model,
        string $attribute,
        RepeatableCollection $repeatables,
        string|int|null $uniqueField,
    ): callable {
        return function () use ($request, $requestAttribute, $model): void {
            if (! $model instanceof Module && ! $model instanceof ModuleActivation) {
                throw new LogicException('Module videos are written onto a module or an activation and nothing else.');
            }

            $operator = $request->user();

            if (! $operator instanceof User) {
                throw new LogicException('A module form was saved without an operator.');
            }

            $rows = $request->input($requestAttribute);
            $videos = [];

            foreach (is_array($rows) ? $rows : [] as $index => $row) {
                $fields = "{$requestAttribute}.{$index}.fields";

                $videos[] = new VideoDetails(
                    title: self::text($request, "{$fields}.title") ?? '',
                    urlField: "{$fields}.url",
                    uploadField: "{$fields}.".VideoUpload::ATTRIBUTE,
                    url: self::text($request, "{$fields}.url"),
                    uploadId: self::text($request, "{$fields}.".VideoUpload::ATTRIBUTE),
                    id: self::text($request, "{$fields}.".ModuleVideoRepeatable::KEY_FIELD),
                );
            }

            app(SaveModuleVideosAction::class)->execute($operator, $model, $videos);
        };
    }

    /** A text field from the form, or null when it was left empty. */
    private static function text(NovaRequest $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
