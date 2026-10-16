<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ContentBlockType;
use App\Support\Modules\BlockCheckboxes;
use App\Support\Modules\BlockDetails;
use App\Support\Modules\BlockTable;
use App\Support\Modules\ContentRules;
use App\Support\Modules\InstructionDetails;
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
 */
final class SavePartRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ContentRules::stepName(),
            'blocks' => ContentRules::blockList(),
            'blocks.*.type' => ['required', new Enum(ContentBlockType::class)],
            'blocks.*.id' => ['nullable', 'uuid'],
            'blocks.*.title' => ContentRules::blockTitle(),
            // Required by kind, so the rule is composed rather than taken whole: everything
            // ContentRules says about markup, asked only of the kind that has a body.
            'blocks.*.body' => [
                'nullable',
                'required_if:blocks.*.type,'.ContentBlockType::Text->value,
                ...ContentRules::markup(ModuleMessages::BLOCK_NEEDS_BODY),
            ],
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

            // A "Stap" block's instructions (KOM-99): rows, not a column. Required by kind, like
            // the table and the options above — the action refuses a block with none, under this
            // key. Each instruction's own picture is multipart, as every other picture here is.
            'blocks.*.instructions' => ContentRules::instructionList(),
            'blocks.*.instructions.*.id' => ['nullable', 'uuid'],
            'blocks.*.instructions.*.title' => ContentRules::instructionTitle(),
            'blocks.*.instructions.*.body' => ContentRules::instructionBody(),
            'blocks.*.instructions.*.image' => ContentRules::optionalImage(),
            'blocks.*.instructions.*.removeImage' => ['nullable', 'boolean'],
        ];
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
            'blocks.*.instructions' => 'stappen',
            'blocks.*.instructions.*.title' => 'kop',
            'blocks.*.instructions.*.body' => 'tekst',
            'blocks.*.instructions.*.image' => 'afbeelding',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'blocks.*.body.required_if' => ModuleMessages::BLOCK_NEEDS_BODY,
            // `required` answers in Laravel's own words otherwise, which name the field rather than
            // say what to do about it.
            'blocks.*.instructions.*.body.required' => ModuleMessages::INSTRUCTION_NEEDS_BODY,
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
                instructionsField: "blocks.{$index}.instructions",
                title: self::stringOf($block, 'title'),
                body: self::stringOf($block, 'body'),
                videoUrl: self::stringOf($block, 'videoUrl'),
                videoUploadId: self::stringOf($block, 'videoUploadId'),
                image: $image instanceof UploadedFile ? $image : null,
                table: BlockTable::fromInput($block['table'] ?? null),
                checkboxes: BlockCheckboxes::fromInput($block['checkboxes'] ?? null),
                instructions: $this->instructionsOf($index, $block['instructions'] ?? null),
                id: self::stringOf($block, 'id'),
            );
        }

        return $blocks;
    }

    /**
     * One block's instructions, in the order they were sent (KOM-99).
     *
     * Each picture is read off the request rather than out of the body, because it is a file: the
     * same multipart a picture block's arrives in, one level deeper.
     *
     * @return list<InstructionDetails>
     */
    private function instructionsOf(int|string $index, mixed $instructions): array
    {
        $details = [];

        foreach (is_array($instructions) ? $instructions : [] as $position => $instruction) {
            if (! is_array($instruction)) {
                continue;
            }

            $imageField = "blocks.{$index}.instructions.{$position}.image";
            $image = $this->file($imageField);

            $details[] = new InstructionDetails(
                imageField: $imageField,
                bodyField: "blocks.{$index}.instructions.{$position}.body",
                title: self::stringOf($instruction, 'title'),
                body: self::stringOf($instruction, 'body'),
                image: $image instanceof UploadedFile ? $image : null,
                removeImage: filter_var($instruction['removeImage'] ?? false, FILTER_VALIDATE_BOOL),
                id: self::stringOf($instruction, 'id'),
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
