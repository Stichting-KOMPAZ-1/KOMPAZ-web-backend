<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Modules\CategoryName;
use Illuminate\Foundation\Http\FormRequest;

/** A category's name, for creating one and for renaming one. */
final class SaveModuleCategoryRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => [new CategoryName],
        ];
    }

    public function name(): string
    {
        return $this->string('name')->toString();
    }
}
