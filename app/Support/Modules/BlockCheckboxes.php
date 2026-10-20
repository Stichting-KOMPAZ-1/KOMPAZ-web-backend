<?php

declare(strict_types=1);

namespace App\Support\Modules;

use JsonException;

/**
 * The options in a "Checkboxes" block (KOM-107): a list of things the reader can tick off.
 *
 * Labels as typed, never markup: a client draws a box beside each and shows the text as written.
 * Nothing is stored about whether a box is ticked — that is the reader's, and this platform keeps
 * no record of a reader at all; the block is the question, not the answer.
 *
 * An option left blank is not an option anybody meant and is dropped, the way an untouched
 * "+ optie" row would be. {@see AcceptableCheckboxes} is what says whether something sent is a set
 * of options; this is what one becomes once it is, and what the column holds
 * ({@see BlockCheckboxesCast}).
 */
final readonly class BlockCheckboxes
{
    /** More than this is a form, not a thing to read through and tick off. */
    public const int MAXIMUM_OPTIONS = 25;

    public const int MAXIMUM_LABEL_LENGTH = 200;

    /** @param  list<string>  $options  the labels, in the order they are shown */
    public function __construct(public array $options) {}

    /**
     * Options from what a form or a request sent: a list of strings, or the JSON the panel's field
     * sends. Null when nothing was sent, or nothing that could be a list of options.
     *
     * Every label is trimmed and the blank ones dropped, so whatever came in, what is kept has one
     * shape.
     */
    public static function fromInput(mixed $input): ?self
    {
        $decoded = self::decode($input);

        if ($decoded === null) {
            return null;
        }

        $options = [];

        foreach ($decoded as $label) {
            $text = is_scalar($label) ? trim((string) $label) : '';

            if ($text !== '') {
                $options[] = $text;
            }
        }

        return $options === [] ? null : new self($options);
    }

    /**
     * What came in, as a plain list, before any of it is judged.
     *
     * Shared with {@see AcceptableCheckboxes} so the rule and the value object read the same thing
     * the same way: the panel's field sends JSON and the API sends an array, and neither should
     * have to know which the other is.
     *
     * @return list<mixed>|null
     */
    public static function decode(mixed $input): ?array
    {
        if (is_string($input)) {
            try {
                $input = json_decode($input, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return null;
            }
        }

        return is_array($input) && array_is_list($input) ? $input : null;
    }

    /** @return list<string> */
    public function toArray(): array
    {
        return $this->options;
    }
}
