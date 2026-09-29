<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReadsContentLists;
use App\Support\Modules\ContentRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * A course's name and the modules that show it.
 *
 * Creating one takes its picture as well, because a course always has one — so a create is
 * multipart. Editing one is plain JSON, and a new picture has its own endpoint. The module list
 * may be left out, which leaves the links as they are.
 */
final class SaveELearningRequest extends FormRequest
{
    use ReadsContentLists;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'name' => ContentRules::eLearningName(),
            'moduleIds' => ['sometimes', 'array'],
            'moduleIds.*' => ['uuid'],
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
            'moduleIds' => 'modules',
            'image' => 'afbeelding',
        ];
    }

    public function name(): string
    {
        return $this->string('name')->toString();
    }

    /** @return list<string>|null */
    public function moduleIds(): ?array
    {
        return $this->keyList('moduleIds');
    }

    public function picture(): ?UploadedFile
    {
        $image = $this->file('image');

        return $this->creates() && $image instanceof UploadedFile ? $image : null;
    }

    private function creates(): bool
    {
        return $this->route('eLearning') === null;
    }
}
