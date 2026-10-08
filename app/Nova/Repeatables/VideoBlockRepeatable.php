<?php

declare(strict_types=1);

namespace App\Nova\Repeatables;

use App\Enums\ContentBlockType;
use App\Nova\Fields\VideoPreview;
use App\Nova\Fields\VideoUpload;
use App\Nova\Fields\WebAddressInput;
use App\Support\Modules\ContentRules;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\Text;

/**
 * A caption, and a linked or an uploaded video.
 *
 * The same pair a module's videos are ({@see ModuleVideoRepeatable}), refused by the same rule
 * with the same sentences: one of the two, never both, and neither keeps the upload the block
 * already has.
 */
class VideoBlockRepeatable extends ContentBlockRepeatable
{
    public static function type(): ContentBlockType
    {
        return ContentBlockType::Video;
    }

    /** @return array<int, Field> */
    protected function contentFields(): array
    {
        return [
            Text::make('Titel van video', 'title')
                ->nullable()
                ->rules(ContentRules::blockTitle()),

            VideoPreview::make('Voorbeeld'),

            WebAddressInput::make('Link naar video', 'video_url')
                ->nullable()
                ->help('Bijvoorbeeld een link naar YouTube of Vimeo.')
                ->rules(ContentRules::optionalUrl()),

            VideoUpload::make('Of upload een video')
                ->help('MP4, MOV of WebM, maximaal 2 GB. Vul een link in óf upload een video, niet allebei.'),
        ];
    }
}
