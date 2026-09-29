<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Modules\ChapterDetails;
use App\Support\Modules\ContentRules;
use Illuminate\Foundation\Http\FormRequest;

final class SaveChapterRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ContentRules::chapterName(),
            'description' => ContentRules::chapterDescription(),
            'isSummary' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => 'naam',
            'description' => 'omschrijving',
            'isSummary' => 'samenvatting',
        ];
    }

    public function details(): ChapterDetails
    {
        $description = $this->validated('description');

        return new ChapterDetails(
            name: $this->string('name')->toString(),
            description: is_string($description) && trim($description) !== '' ? $description : null,
            isSummary: $this->boolean('isSummary'),
        );
    }
}
