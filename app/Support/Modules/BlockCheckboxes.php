<?php

declare(strict_types=1);

namespace App\Support\Modules;

use Illuminate\Support\Str;
use JsonException;
use LogicException;

/**
 * The options in a "Checkboxes" block (KOM-107): a list of things the reader can tick off.
 *
 * Labels as typed, never markup: a client draws a box beside each and shows the text as written.
 * Nothing is stored about whether a box is ticked — that is the reader's, and this platform keeps
 * no record of a reader at all; the block is the question, not the answer. What a client may keep
 * on the reader's device is which options were ticked, so every option carries an identifier that
 * outlives a reworded label or a new order ({@see BlockCheckboxOption}).
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

    /** @param  list<BlockCheckboxOption>  $options  in the order they are shown */
    public function __construct(public array $options) {}

    /**
     * Options from what a form or a request sent, or from the column: a list whose items are a
     * label, or `{id, label}` for an option that is being kept. Accepted as the JSON the panel's
     * field sends as well as an array. Null when nothing was sent, or nothing that could be a list
     * of options.
     *
     * Every label is trimmed and the blank ones dropped, so whatever came in, what is kept has one
     * shape. An identifier that came in is only a claim until {@see keepingIdsOf()} has checked it.
     */
    public static function fromInput(mixed $input): ?self
    {
        $decoded = self::decode($input);

        if ($decoded === null) {
            return null;
        }

        $options = [];

        foreach ($decoded as $item) {
            $label = self::labelOf($item);

            if ($label !== '') {
                $id = is_array($item) && is_string($item['id'] ?? null) && $item['id'] !== '' ? $item['id'] : null;
                $options[] = new BlockCheckboxOption($id, $label);
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

    /**
     * One item's label, trimmed: the item itself when it is a label, or its `label` when it is an
     * option sent back with its identifier. Empty when there is none.
     */
    public static function labelOf(mixed $item): string
    {
        $label = is_array($item) ? ($item['label'] ?? null) : $item;

        return is_scalar($label) ? trim((string) $label) : '';
    }

    /**
     * These options, each with an identifier: the one it was sent with when that names an option
     * the block already has, and a new one otherwise.
     *
     * The rule every list here follows for a key a client sends (rule 25): it is looked up among
     * that block's own options only, and used once — so an identifier copied from another block,
     * made up, or sent twice starts a new option rather than taking over somebody else's ticks.
     */
    public function keepingIdsOf(?self $existing): self
    {
        $known = [];

        foreach ($existing->options ?? [] as $option) {
            if ($option->id !== null) {
                $known[$option->id] = true;
            }
        }

        $options = [];

        foreach ($this->options as $option) {
            $id = $option->id !== null && isset($known[$option->id]) ? $option->id : (string) Str::uuid7();
            unset($known[$id]);

            $options[] = new BlockCheckboxOption($id, $option->label);
        }

        return new self($options);
    }

    /** @return list<array{id: string|null, label: string}> */
    public function toArray(): array
    {
        return array_map(
            static fn (BlockCheckboxOption $option): array => ['id' => $option->id, 'label' => $option->label],
            $this->options,
        );
    }

    /**
     * The options as a row holds them, every one with its identifier.
     *
     * @return list<array{id: string, label: string}>
     */
    public function toStored(): array
    {
        return array_map(static function (BlockCheckboxOption $option): array {
            if ($option->id === null) {
                throw new LogicException('Checkbox options are given their identifiers before they are stored.');
            }

            return ['id' => $option->id, 'label' => $option->label];
        }, $this->options);
    }
}
