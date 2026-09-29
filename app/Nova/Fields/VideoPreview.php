<?php

declare(strict_types=1);

namespace App\Nova\Fields;

use App\Models\ContentBlock;
use App\Models\Contracts\HoldsVideo;
use App\Models\ModuleVideo;
use App\Support\Videos\VideoEmbed;
use Laravel\Nova\Fields\Field;

/**
 * What a video row shows, as the saved row has it: its upload, or its link.
 *
 * Read-only and writing nothing — its fill does nothing, and the presets that save these rows never
 * read it. It shows the row as it was saved, not as the form is being typed: an operator pastes a
 * link, saves, and sees it back. A new row has nothing to show yet and says so.
 *
 * An upload plays through the panel's preview route, which asks who may see it. A link is shown as
 * {@see VideoEmbed} works it out, which builds any embed address itself from the video's id.
 */
final class VideoPreview extends Field
{
    public const string ATTRIBUTE = 'video_preview';

    /** @var string */
    public $component = 'video-preview';

    public function __construct(string $name)
    {
        parent::__construct($name, self::ATTRIBUTE);

        $this->fillUsing(static fn (): null => null);

        $this->withMeta([
            'emptyLabel' => 'Sla eerst op om de video hier te bekijken.',
            'openLabel' => 'Link openen',
            'uploadLabel' => 'Geüploade video',
        ]);
    }

    /**
     * The saved row's video, as something to show.
     *
     * @param  mixed  $resource
     */
    #[\Override]
    protected function resolveAttribute($resource, string $attribute): mixed
    {
        $this->withMeta(['preview' => $resource instanceof HoldsVideo ? self::previewOf($resource) : null]);

        return null;
    }

    /** @return array{kind: string, src: string}|null */
    private static function previewOf(HoldsVideo $video): ?array
    {
        if ($video->file() !== null) {
            $src = self::uploadAddress($video);

            return $src === null ? null : ['kind' => 'upload', 'src' => $src];
        }

        $url = $video->videoUrl();
        $embed = $url === null ? null : VideoEmbed::fromUrl($url);

        return $embed === null ? null : ['kind' => $embed->kind, 'src' => $embed->src];
    }

    /**
     * Where the panel plays an upload back, relative to its origin.
     *
     * Relative, like every other address the panel's scripts use, because the panel is reached
     * through the frontend's domain as well as its own.
     */
    private static function uploadAddress(HoldsVideo $video): ?string
    {
        return match (true) {
            $video instanceof ModuleVideo => route('nova.video-preview.module-video', ['moduleVideo' => $video->getKey()], absolute: false),
            $video instanceof ContentBlock => route('nova.video-preview.content-block', ['contentBlock' => $video->getKey()], absolute: false),
            default => null,
        };
    }
}
