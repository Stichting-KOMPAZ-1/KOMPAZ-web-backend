<?php

declare(strict_types=1);

namespace App\Nova\Repeatables;

use App\Enums\ContentBlockType;
use App\Models\ContentBlock;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\Hidden;
use Laravel\Nova\Fields\Repeater\Repeatable;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * One block of a step, as the step form's "+ blok" menu offers it.
 *
 * Three subclasses over one model, which Nova's own has-many preset cannot tell apart — it picks a
 * repeatable by model class, so every block would come back as whichever type is listed first.
 * {@see ContentBlockPreset} picks by {@see type()} instead.
 *
 * Each row carries its block's key in a hidden field, which is what lets an edit keep a picture
 * nobody uploaded again. The preset only ever looks that key up among the step's own blocks.
 */
abstract class ContentBlockRepeatable extends Repeatable
{
    /** @var class-string<ContentBlock> */
    public static $model = ContentBlock::class;

    /** The row's own key, which the preset reads and never writes. */
    public const string KEY_FIELD = 'id';

    /** Which kind of block this row writes. */
    abstract public static function type(): ContentBlockType;

    /**
     * The fields that make this kind of block what it is.
     *
     * @return array<int, Field>
     */
    abstract protected function contentFields(): array;

    public static function label(): string
    {
        return static::type()->label();
    }

    public static function singularLabel(): string
    {
        return static::type()->label();
    }

    /** @return array<int, Field> */
    public function fields(NovaRequest $request): array
    {
        return [
            Hidden::make(self::KEY_FIELD),
            ...$this->contentFields(),
        ];
    }
}
