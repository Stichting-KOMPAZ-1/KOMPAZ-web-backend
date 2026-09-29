<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The three things a step can be built out of.
 *
 * Which columns a block must fill follows from this and from nothing else, which is why the
 * database states the same three rules as check constraints: the type is the discriminator, and a
 * row whose columns disagree with it is not a block of another kind, it is a broken one.
 */
enum ContentBlockType: string
{
    /** An optional heading and a body. Plain text for now, by product decision. */
    case Text = 'Text';

    /** An optional caption and a picture. */
    case Image = 'Image';

    /** An optional caption and a video, either uploaded or linked. */
    case Video = 'Video';

    /** What this kind of block is called in the panel. */
    public function label(): string
    {
        return match ($this) {
            self::Text => 'Tekst',
            self::Image => 'Afbeelding',
            self::Video => 'Video',
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
