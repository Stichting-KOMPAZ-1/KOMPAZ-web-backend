<?php

declare(strict_types=1);

namespace App\Nova\Repeatables;

use App\Enums\ContentBlockType;
use App\Models\ContentBlock;
use App\Nova\Concerns\StoresUploadedImage;
use App\Support\Images\AcceptableLogo;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\Image;
use Laravel\Nova\Fields\Text;

/**
 * A caption and a picture.
 *
 * The picture is optional *on the field* because an edit that leaves it alone sends nothing for
 * it, and Nova validates a repeater's rows with one set of rules whether the row is new or not. A
 * new picture block without an upload is refused by {@see ContentBlockPreset}, which is the one
 * place that knows whether the row already had a file.
 */
class ImageBlockRepeatable extends ContentBlockRepeatable
{
    use StoresUploadedImage;

    public static function type(): ContentBlockType
    {
        return ContentBlockType::Image;
    }

    /** @return array<int, Field> */
    protected function contentFields(): array
    {
        return [
            Text::make('Titel van afbeelding', 'title')
                ->nullable()
                ->rules(['nullable', 'string', 'max:'.ContentBlock::MAXIMUM_TITLE_LENGTH]),

            Image::make('Afbeelding', 'file_storage_key')
                ->disk(config('filesystems.default'))
                ->rules(['nullable', new AcceptableLogo])
                ->store($this->storesImageUnder(ContentBlock::FILE_PREFIX, 'file'))
                // Both through the panel's route. Nova's default thumbnail is the disk's public
                // address, and this disk is private, so the default is a broken image.
                ->preview(self::fileUrl(...))
                ->thumbnail(self::fileUrl(...))
                ->prunable(false)
                ->deletable(false),
        ];
    }

    /**
     * Where the panel reads this block's picture back from.
     *
     * A row added in the browser has no block behind it yet, and Nova resolves it against an empty
     * array rather than a model.
     */
    private static function fileUrl(mixed $value, ?string $disk, mixed $block): ?string
    {
        return $block instanceof ContentBlock && $block->file() !== null
            ? route('nova.content-block-file', ['block' => (string) $block->getKey()])
            : null;
    }
}
