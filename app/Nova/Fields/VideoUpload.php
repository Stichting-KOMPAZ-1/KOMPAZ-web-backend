<?php

declare(strict_types=1);

namespace App\Nova\Fields;

use App\Models\Contracts\HoldsVideo;
use App\Support\Videos\VideoMessages;
use Laravel\Nova\Fields\Field;

/**
 * Uploading a video from inside a form, straight to the video container.
 *
 * Nova's own file fields post the bytes with the form, and a form carrying two gigabytes through a
 * PHP request is the one thing this is here to avoid. So the browser asks for an upload link the
 * moment a file is picked, writes the file to Azure in blocks while the operator goes on typing,
 * has it looked at, and sends the form nothing but the upload's key. The component is in
 * `resources/nova/panel`.
 *
 * The field writes no column. It lives in a repeater row, and the presets that save those rows
 * read its key out of the request and hand it to the use case that claims it — which is why its
 * own fill does nothing. What it resolves to is never a value either: an existing upload is shown,
 * not sent back, since sending nothing is how a row keeps the video it has.
 */
final class VideoUpload extends Field
{
    /** The request key a row's upload arrives under. */
    public const string ATTRIBUTE = 'video_upload';

    /**
     * Where the component asks for a link and reports a finished upload, relative to the panel's
     * origin. Under `/nova-vendor`, which the frontend's nginx already forwards here.
     */
    public const string UPLOADS_PATH = '/nova-vendor/kompaz/video-uploads';

    /** @var string */
    public $component = 'video-upload';

    public function __construct(string $name)
    {
        parent::__construct($name, self::ATTRIBUTE);

        $this->fillUsing(static fn (): null => null);

        $this->withMeta([
            'uploadsPath' => self::UPLOADS_PATH,
            'maximumBytes' => VideoMessages::maximumBytes(),
            'blockSizeBytes' => (int) config('kompaz.videos.block_size_bytes'),
            'accept' => 'video/mp4,video/quicktime,video/webm',
            'tooLargeLabel' => VideoMessages::tooLarge(),
            'chooseLabel' => 'Video uploaden',
            'replaceLabel' => 'Andere video uploaden',
            'uploadingLabel' => 'Bezig met uploaden…',
            'checkingLabel' => 'Video wordt gecontroleerd…',
            'doneLabel' => 'Geüpload. Sla het formulier op om de video te bewaren.',
            'currentLabel' => 'Geüploade video',
            'noneLabel' => 'Geen geüploade video',
            'failedLabel' => 'Het uploaden is mislukt. Probeer het opnieuw.',
            'leaveWarning' => 'Er wordt nog een video geüpload. Weet je zeker dat je deze pagina wilt verlaten?',
        ]);
    }

    /**
     * The video the row already has, as something to show rather than a value to send back.
     *
     * @param  mixed  $resource
     */
    #[\Override]
    protected function resolveAttribute($resource, string $attribute): mixed
    {
        $file = $resource instanceof HoldsVideo ? $resource->file() : null;

        $this->withMeta([
            'current' => $file === null ? null : [
                'contentType' => $file->contentType,
                'byteCount' => $file->byteCount,
            ],
        ]);

        return null;
    }
}
