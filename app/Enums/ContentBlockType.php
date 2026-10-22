<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\CarouselSlide;

/**
 * The seven things a part (a step, in the code) can be built out of.
 *
 * Which columns a block must fill follows from this and from nothing else, which is why the
 * database states the same seven rules as check constraints: the type is the discriminator, and a
 * row whose columns disagree with it is not a block of another kind, it is a broken one.
 */
enum ContentBlockType: string
{
    /** An optional heading and a body, the body rich text stored as markup. */
    case Text = 'Text';

    /** An optional caption and a picture. */
    case Image = 'Image';

    /** An optional caption and a video, either uploaded or linked. */
    case Video = 'Video';

    /** An optional title and a table of plain text: column headers and rows (KOM-101). */
    case Table = 'Table';

    /** An optional title and a list of options the reader ticks off (KOM-107). */
    case Checkboxes = 'Checkboxes';

    /**
     * One numbered step of a sequence (KOM-99): a title, an optional body and an optional picture.
     * Each step is a block of its own, so other blocks can go between two of them; its number is
     * not stored but counted from the steps before it on the same part.
     */
    case Instruction = 'Instruction';

    /**
     * A carousel (KOM-100): slides a reader pages through in one place, each a picture with a title
     * and a body it may have. The one kind whose content is rows rather than columns
     * ({@see CarouselSlide}); the block itself holds nothing.
     */
    case Carousel = 'Carousel';

    /** What this kind of block is called in the panel. */
    public function label(): string
    {
        return match ($this) {
            self::Text => 'Tekst',
            self::Image => 'Afbeelding',
            self::Video => 'Video',
            self::Table => 'Tabel',
            self::Checkboxes => 'Checkboxes',
            // The product's word. In the code it is an instruction, because a step here is the part
            // these blocks sit on.
            self::Instruction => 'Stap',
            self::Carousel => 'Carrousel',
        };
    }

    /**
     * Every kind as a select's options: the stored value against the name it is shown under.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $type) {
            $options[$type->value] = $type->label();
        }

        return $options;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
