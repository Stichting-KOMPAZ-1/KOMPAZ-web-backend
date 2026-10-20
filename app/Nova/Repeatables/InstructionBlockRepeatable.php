<?php

declare(strict_types=1);

namespace App\Nova\Repeatables;

use App\Enums\ContentBlockType;
use App\Nova\Fields\RichText;
use App\Support\Modules\ContentRules;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\Image;
use Laravel\Nova\Fields\Text;

/**
 * One numbered step (KOM-99): a title, and a body and a picture it may have.
 *
 * Each step is a block of its own, so the operator can put other blocks between two of them. The
 * number is not a field: it is counted from the steps before it on the part when the part is read.
 *
 * The picture is kept unless a new one is uploaded, as a picture block's is. Nova's repeater
 * cannot delete a file from inside a row, so taking one away in the panel means replacing the
 * block; the API can (`removeImage`).
 */
class InstructionBlockRepeatable extends ContentBlockRepeatable
{
    public static function type(): ContentBlockType
    {
        return ContentBlockType::Instruction;
    }

    /** @return array<int, Field> */
    protected function contentFields(): array
    {
        return [
            Text::make('Titel van stap', 'title')
                ->rules(ContentRules::instructionTitle()),

            RichText::make('Body van stap', 'body')
                ->nullable()
                ->rules(ContentRules::optionalBlockBody()),

            Image::make('Afbeelding', 'file_storage_key')
                ->disk(config('filesystems.default'))
                ->rules(ContentRules::optionalImage())
                ->preview(self::fileUrl(...))
                ->thumbnail(self::fileUrl(...))
                ->prunable(false)
                ->deletable(false),
        ];
    }
}
