<?php

declare(strict_types=1);

namespace App\Nova\Repeatables;

use App\Models\ModuleVideo;
use App\Support\Modules\ContentRules;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\Repeater\Repeatable;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\URL;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * One row of the "Video's" list: a title and a link.
 *
 * Linked videos only, deliberately. The table allows an upload instead — "Of upload een video" in
 * the wireframe — and the column is there waiting, but serving one is the part that is not ready:
 * files are read back into memory whole, which is right for a picture and wrong for a video, and
 * the hosting question behind it is open. Half a video uploader is worse than none.
 */
class ModuleVideoRepeatable extends Repeatable
{
    /** @var class-string<ModuleVideo> */
    public static $model = ModuleVideo::class;

    public static function label(): string
    {
        return 'Video';
    }

    /** @return array<int, Field> */
    public function fields(NovaRequest $request): array
    {
        return [
            Text::make('Titel', 'title')
                ->rules(ContentRules::videoTitle()),

            URL::make('URL', 'url')
                ->rules(ContentRules::url()),
        ];
    }
}
