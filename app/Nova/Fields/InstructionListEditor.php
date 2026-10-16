<?php

declare(strict_types=1);

namespace App\Nova\Fields;

use App\Models\BlockInstruction;
use App\Models\ContentBlock;
use App\Nova\Repeatables\ContentBlockPreset;
use Laravel\Nova\Fields\Field;

/**
 * The instructions of a "Stap" block, written in the panel (KOM-99): a heading, a text and a
 * picture per instruction, "+ stap" to add one and a cross to take it away.
 *
 * Nova's repeater cannot nest a repeating list inside a block's row, which is why the table and the
 * checkbox options have components of their own; this is the third and the largest, because an
 * instruction also carries a file. The list travels as JSON and each picture beside it as a file at
 * `instructions_images.{position}`, which is this attribute with `_images` after it — so neither
 * the component nor {@see ContentBlockPreset} has to take the other's path apart.
 *
 * The field writes no column itself. It lives in a block's row, and the preset reads it out of the
 * request for the use case that saves every block — which is why its own fill does nothing.
 */
final class InstructionListEditor extends Field
{
    /** @var string */
    public $component = 'instruction-list-editor';

    public function __construct(string $name, string $attribute)
    {
        parent::__construct($name, $attribute);

        $this->fillUsing(static fn (): null => null);

        $this->withMeta([
            'maximumInstructions' => ContentBlock::MAXIMUM_INSTRUCTIONS,
            'maximumTitleLength' => BlockInstruction::MAXIMUM_TITLE_LENGTH,
            'titlePlaceholder' => 'Kop van deze stap',
            'bodyPlaceholder' => 'Tekst van deze stap',
            'addInstructionLabel' => '+ stap',
            'removeInstructionLabel' => 'Stap verwijderen',
            'imageLabel' => 'Afbeelding',
            'replaceImageLabel' => 'Vervangen',
            'removeImageLabel' => 'Afbeelding verwijderen',
            'emptyLabel' => 'Geen stappen',
        ]);
    }

    /**
     * The block's instructions as the component edits them, with each picture as the address it is
     * read back from rather than its bytes.
     *
     * @param  mixed  $resource
     * @return list<array{id: string, title: ?string, body: string, imageUrl: ?string}>|null
     */
    #[\Override]
    protected function resolveAttribute($resource, string $attribute): ?array
    {
        if (! $resource instanceof ContentBlock) {
            return null;
        }

        return $resource->instructions
            ->map(static fn (BlockInstruction $instruction): array => [
                'id' => (string) $instruction->getKey(),
                'title' => $instruction->title,
                'body' => (string) $instruction->body,
                'imageUrl' => $instruction->file() === null
                    ? null
                    : route('nova.block-instruction-image', ['instruction' => $instruction->getKey()]),
            ])
            ->values()
            ->all();
    }
}
