<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ContentBlockType;
use App\Support\Modules\BlockDetails;
use App\Support\Modules\ContentRules;
use App\Support\Modules\ModuleMessages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rules\Enum;

/**
 * A step and every block on it, in order.
 *
 * Multipart when it carries a picture, and so then sent as a POST with `_method=PUT` for an edit:
 * PHP reads no files out of a real PUT. A picture block that keeps its picture sends its `id` and
 * no `image`; whether a new one without a picture is acceptable is the action's to decide, because
 * only the step's rows know whether that `id` already has a file.
 */
final class SaveStepRequest extends FormRequest
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
            // Required by kind: the rule is ContentRules' own, asked only of the kind that has one.
            'blocks.*.body' => ['nullable', 'required_if:blocks.*.type,'.ContentBlockType::Text->value, 'string'],
            'blocks.*.videoUrl' => ['nullable', 'required_if:blocks.*.type,'.ContentBlockType::Video->value, 'url', 'max:'.ContentRules::MAXIMUM_URL_LENGTH],
            'blocks.*.image' => ContentRules::optionalImage(),
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
            'blocks.*.image' => 'afbeelding',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'blocks.*.body.required_if' => ModuleMessages::BLOCK_NEEDS_BODY,
            'blocks.*.videoUrl.required_if' => ModuleMessages::BLOCK_NEEDS_VIDEO,
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
                title: self::stringOf($block, 'title'),
                body: self::stringOf($block, 'body'),
                videoUrl: self::stringOf($block, 'videoUrl'),
                image: $image instanceof UploadedFile ? $image : null,
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
