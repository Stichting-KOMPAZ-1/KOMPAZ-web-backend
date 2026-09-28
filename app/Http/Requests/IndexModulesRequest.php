<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ModuleStatus;
use App\Models\Module;
use App\Support\Pagination\PaginatedList;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexModulesRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:'.Module::MAXIMUM_NAME_LENGTH],
            'category' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::enum(ModuleStatus::class)],
            'pageNumber' => ['nullable', 'integer', 'min:1'],
            'pageSize' => ['nullable', 'integer', 'min:1', 'max:'.PaginatedList::MAXIMUM_PAGE_SIZE],
        ];
    }

    public function pageNumber(): int
    {
        return (int) ($this->validated('pageNumber') ?? 1);
    }

    public function pageSize(): int
    {
        return (int) ($this->validated('pageSize') ?? PaginatedList::DEFAULT_PAGE_SIZE);
    }
}
