<?php

declare(strict_types=1);

namespace App\Nova\Repeatables;

use App\Actions\Videos\ApplyVideoSourceAction;
use App\Models\ModuleVideo;
use App\Nova\Fields\VideoUpload;
use App\Support\Modules\ContentRules;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\Hidden;
use Laravel\Nova\Fields\Repeater\Repeatable;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\URL;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * One row of the "Video's" list: a title, and a link or an upload.
 *
 * "Of upload een video", as the wireframe has it. Neither field is required on its own, because
 * the rule is about the pair — one of the two, never both — and because a row that already has an
 * upload keeps it by sending neither. That rule is {@see ApplyVideoSourceAction}'s,
 * shared with the API, and it answers under whichever field it is about.
 *
 * The row carries its video's key in a hidden field, which is what lets an edit keep an upload:
 * {@see ModuleVideoPreset} looks it up among this list's own rows.
 */
class ModuleVideoRepeatable extends Repeatable
{
    /** @var class-string<ModuleVideo> */
    public static $model = ModuleVideo::class;

    /** The row's own key, which the preset reads and never writes. */
    public const string KEY_FIELD = 'id';

    public static function label(): string
    {
        return 'Video';
    }

    /** @return array<int, Field> */
    public function fields(NovaRequest $request): array
    {
        return [
            Hidden::make(self::KEY_FIELD),

            Text::make('Titel', 'title')
                ->rules(ContentRules::videoTitle()),

            URL::make('Link naar video', 'url')
                ->nullable()
                ->help('Bijvoorbeeld een link naar YouTube of Vimeo.')
                ->rules(ContentRules::optionalUrl()),

            VideoUpload::make('Of upload een video')
                ->help('MP4, MOV of WebM, maximaal 2 GB. Vul een link in óf upload een video, niet allebei.'),
        ];
    }
}
