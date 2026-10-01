<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ModuleStatus;
use App\Http\Requests\Concerns\ReadsContentLists;
use App\Support\Modules\ContentRules;
use App\Support\Modules\ModuleDetails;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * A module, whole, as the platform writes it — for creating one and for writing a new version.
 *
 * The fields are the panel's form's, and so are their rules ({@see ContentRules}). The four lists
 * may be left out, which leaves them as they are.
 *
 * Creating one takes its picture as well, because a module is created with one (KOM-41) — so a
 * create is multipart. Editing one is plain JSON, and a new picture has its own endpoint.
 */
final class SaveModuleRequest extends FormRequest
{
    use ReadsContentLists;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'name' => ContentRules::moduleName(),
            'categoryId' => ContentRules::moduleCategory(),
            'description' => ContentRules::moduleDescription(),
            'sourceAttribution' => ContentRules::sourceAttribution(),
            'status' => ContentRules::moduleStatus(),
            'eLearningIds' => ['sometimes', 'array'],
            'eLearningIds.*' => ['uuid'],
            'organizationIds' => ['sometimes', 'array'],
            'organizationIds.*' => ['uuid'],
            ...self::videoRules(),
            ...self::linkRules(),
        ];

        if ($this->creates()) {
            $rules['image'] = ContentRules::requiredImage();
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => 'naam',
            'categoryId' => 'categorie',
            'description' => 'omschrijving',
            'sourceAttribution' => 'bronvermelding',
            'status' => 'status',
            'eLearningIds' => 'e-learnings',
            'organizationIds' => 'actief bij',
            'image' => 'afbeelding',
            ...self::listAttributes(),
        ];
    }

    public function details(): ModuleDetails
    {
        $source = $this->validated('sourceAttribution');

        return new ModuleDetails(
            name: $this->string('name')->toString(),
            categoryId: $this->string('categoryId')->toString(),
            description: $this->string('description')->toString(),
            sourceAttribution: is_string($source) && trim($source) !== '' ? $source : null,
            status: ModuleStatus::from($this->string('status')->toString()),
            eLearningIds: $this->keyList('eLearningIds'),
            organizationIds: $this->keyList('organizationIds'),
            videos: $this->videoList(),
            links: $this->linkList('links'),
        );
    }

    public function picture(): ?UploadedFile
    {
        $image = $this->file('image');

        return $this->creates() && $image instanceof UploadedFile ? $image : null;
    }

    private function creates(): bool
    {
        return $this->route('module') === null;
    }
}
