<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ContentBlockType;
use App\Support\Modules\BlockCheckboxes;
use App\Support\Modules\BlockDetails;
use App\Support\Modules\BlockTable;
use App\Support\Modules\ContentRules;
use App\Support\Modules\ModuleMessages;
use App\Support\Modules\SlideDetails;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rules\Enum;

/**
 * A part and every block on it, in order.
 *
 * Multipart when it carries a picture, and so then sent as a POST with `_method=PUT` for an edit:
 * PHP reads no files out of a real PUT. A picture block that keeps its picture sends its `id` and
 * no `image`; whether a new one without a picture is acceptable is the action's to decide, because
 * only the step's rows know whether that `id` already has a file. A video block is the same with
 * `videoUrl` or `videoUploadId`: the upload itself went straight to the video container, and this
 * names it.
 *
 * A "Stap" block's picture is optional, so it is the one kind that can lose one: `removeImage`
 * takes it away, and leaving out `image` keeps it.
 */
final class SavePartRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'name' => ContentRules::stepName(),
            'blocks' => ContentRules::blockList(),
            'blocks.*.type' => ['required', new Enum(ContentBlockType::class)],
            'blocks.*.id' => ['nullable', 'uuid'],
            // Optional for every kind but a "Stap" (KOM-99), which is its title.
            'blocks.*.title' => [
                'required_if:blocks.*.type,'.ContentBlockType::Instruction->value,
                ...ContentRules::blockTitle(),
            ],
            // Optional here, and required of a text block below. A "Stap" and a checkbox block may
            // have one; the other kinds' is ignored.
            'blocks.*.body' => ContentRules::optionalBlockBody(),
            // A link or an upload. Neither keeps a video block's existing upload, which only the
            // part's rows know about, so "one of the two" is the action's to refuse.
            'blocks.*.videoUrl' => ContentRules::optionalUrl(),
            'blocks.*.videoUploadId' => ContentRules::videoUploadId(),
            'blocks.*.image' => ContentRules::optionalImage(),
            // `{columns: [...], rows: [[...]]}`. Required by kind, as a picture is: the action
            // refuses a table block without one, under this key.
            'blocks.*.table' => ContentRules::blockTable(),
            // A list of labels. Required by kind, like the table above: the action refuses a
            // checkbox block with no options, under this key.
            'blocks.*.checkboxes' => ContentRules::blockCheckboxes(),

            'blocks.*.removeImage' => ['nullable', 'boolean'],

            // A carousel's slides (KOM-100): rows, not a column, each with its own picture sent
            // multipart as every other picture here is. Whether the carousel has any, and whether
            // each has its title and picture, is the action's to refuse — it alone knows which
            // slides already have a picture, and says which slide it means.
            'blocks.*.slides' => ContentRules::slideList(),
            'blocks.*.slides.*.id' => ['nullable', 'uuid'],
            'blocks.*.slides.*.title' => ContentRules::slideTitle(),
            'blocks.*.slides.*.body' => ContentRules::optionalBlockBody(),
            'blocks.*.slides.*.image' => ContentRules::optionalImage(),
        ];

        // A text block's body is what the block is, and it has to survive being cleaned. Asked of
        // that kind alone, because markup that strips to nothing is simply no body on the kinds
        // where one is optional, rather than a refusal.
        foreach ($this->array('blocks') as $index => $block) {
            if (is_array($block) && ($block['type'] ?? null) === ContentBlockType::Text->value) {
                $rules["blocks.{$index}.body"] = ['required', ...ContentRules::markup(ModuleMessages::BLOCK_NEEDS_BODY)];
            }
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => 'naam',
            'blocks' => 'inhoud',
            'blocks.*.type' => 'type blok',
            'blocks.*.title' => 'titel',
            'blocks.*.body' => 'tekst',
            'blocks.*.videoUrl' => 'video',
            'blocks.*.videoUploadId' => 'upload',
            'blocks.*.image' => 'afbeelding',
            'blocks.*.table' => 'tabel',
            'blocks.*.checkboxes' => 'opties',
            'blocks.*.slides' => 'slides',
            'blocks.*.slides.*.title' => 'titel',
            'blocks.*.slides.*.image' => 'afbeelding',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'blocks.*.body.required' => ModuleMessages::BLOCK_NEEDS_BODY,
            'blocks.*.title.required_if' => ModuleMessages::INSTRUCTION_NEEDS_TITLE,
        ];
    }

    public function name(): string
    {
        return $this->string('name')->toString();
    }

    /** @return list<BlockDetails> */
    public function blocks(): array
    {
        $blocks = [];

        foreach ($this->array('blocks') as $index => $block) {
            if (! is_array($block)) {
                continue;
            }

            $image = $this->file("blocks.{$index}.image");

            $blocks[] = new BlockDetails(
                type: ContentBlockType::from(self::stringOf($block, 'type') ?? ''),
                imageField: "blocks.{$index}.image",
                videoUrlField: "blocks.{$index}.videoUrl",
                videoUploadField: "blocks.{$index}.videoUploadId",
                tableField: "blocks.{$index}.table",
                checkboxesField: "blocks.{$index}.checkboxes",
                titleField: "blocks.{$index}.title",
                slidesField: "blocks.{$index}.slides",
                title: self::stringOf($block, 'title'),
                body: self::stringOf($block, 'body'),
                videoUrl: self::stringOf($block, 'videoUrl'),
                videoUploadId: self::stringOf($block, 'videoUploadId'),
                image: $image instanceof UploadedFile ? $image : null,
                table: BlockTable::fromInput($block['table'] ?? null),
                checkboxes: BlockCheckboxes::fromInput($block['checkboxes'] ?? null),
                removeImage: filter_var($block['removeImage'] ?? false, FILTER_VALIDATE_BOOL),
                slides: $this->slidesOf($index, $block['slides'] ?? null),
                id: self::stringOf($block, 'id'),
            );
        }

        return $blocks;
    }

    /**
     * One carousel's slides, in the order they were sent (KOM-100). Each picture is read off the
     * request rather than out of the body, because it is a file.
     *
     * @return list<SlideDetails>
     */
    private function slidesOf(int|string $index, mixed $slides): array
    {
        $details = [];

        foreach (is_array($slides) ? array_values($slides) : [] as $position => $slide) {
            if (! is_array($slide)) {
                continue;
            }

            $image = $this->file("blocks.{$index}.slides.{$position}.image");

            $details[] = new SlideDetails(
                titleField: "blocks.{$index}.slides.{$position}.title",
                imageField: "blocks.{$index}.slides.{$position}.image",
                title: self::stringOf($slide, 'title'),
                body: self::stringOf($slide, 'body'),
                image: $image instanceof UploadedFile ? $image : null,
                id: self::stringOf($slide, 'id'),
            );
        }

        return $details;
    }

    /** @param  array<mixed>  $block */
    private static function stringOf(array $block, string $key): ?string
    {
        $value = $block[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
