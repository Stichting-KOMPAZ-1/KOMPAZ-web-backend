<?php

declare(strict_types=1);

namespace App\Nova\Repeatables;

use App\Enums\ContentBlockType;
use App\Support\Modules\ContentRules;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\URL;

/**
 * A caption and a linked video.
 *
 * Linked only, for the reason a module's videos are ({@see ModuleVideoRepeatable}): the table can
 * hold an upload, but a served file is read into memory whole, which is right for a picture and
 * wrong for a video, and where videos are hosted is still an open question.
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

            URL::make('Video', 'video_url')
                ->rules(ContentRules::url()),
        ];
    }
}
