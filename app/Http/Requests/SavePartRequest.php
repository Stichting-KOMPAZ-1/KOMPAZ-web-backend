<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ContentBlockType;
use App\Support\Modules\BlockCheckboxes;
use App\Support\Modules\BlockDetails;
use App\Support\Modules\BlockTable;
use App\Support\Modules\ContentRules;
use App\Support\Modules\ModuleMessages;
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
            // Optional for every kind but a "Stap" (KOM-99) and a carousel slide (KOM-100), whose
            // title is what the reader is shown beside the picture.
            'blocks.*.title' => [
                'required_if:blocks.*.type,'.ContentBlockType::Instruction->value.','.ContentBlockType::CarouselSlide->value,
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
                title: self::stringOf($block, 'title'),
                body: self::stringOf($block, 'body'),
                videoUrl: self::stringOf($block, 'videoUrl'),
                videoUploadId: self::stringOf($block, 'videoUploadId'),
                image: $image instanceof UploadedFile ? $image : null,
                table: BlockTable::fromInput($block['table'] ?? null),
                checkboxes: BlockCheckboxes::fromInput($block['checkboxes'] ?? null),
                removeImage: filter_var($block['removeImage'] ?? false, FILTER_VALIDATE_BOOL),
                id: self::stringOf($block, 'id'),
            );
        }

        return $blocks;
    }

    /** @param  array<mixed>  $block */
    private static function stringOf(array $block, string $key): ?string
    {
        $value = $block[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
