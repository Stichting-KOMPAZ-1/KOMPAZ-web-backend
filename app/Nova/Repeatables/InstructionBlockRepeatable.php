<?php

declare(strict_types=1);

namespace App\Nova\Repeatables;

use App\Enums\ContentBlockType;
use App\Nova\Fields\InstructionListEditor;
use App\Support\Modules\ContentRules;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\Text;

/**
 * An optional title and a numbered sequence of instructions (KOM-99), each with a heading, its own
 * text and a picture of its own.
 *
 * The block's own title is a plain field; the instructions are not, because Nova's repeater cannot
 * nest a repeating list inside this row ({@see InstructionListEditor}).
 */
class InstructionBlockRepeatable extends ContentBlockRepeatable
{
    public static function type(): ContentBlockType
    {
        return ContentBlockType::Instructions;
    }

    /** @return array<int, Field> */
    protected function contentFields(): array
    {
        return [
            Text::make('Titel van stappen', 'title')
                ->nullable()
                ->rules(ContentRules::blockTitle()),

            InstructionListEditor::make('Stappen', 'instructions')
                ->rules(ContentRules::instructionList()),
        ];
    }
}
