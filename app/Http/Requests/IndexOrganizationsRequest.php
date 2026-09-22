<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Organization;
use App\Support\Pagination\PaginatedList;
use Illuminate\Foundation\Http\FormRequest;

final class IndexOrganizationsRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:'.Organization::MAXIMUM_NAME_LENGTH],
            'pageNumber' => ['nullable', 'integer', 'min:1'],
            'pageSize' => ['nullable', 'integer', 'min:1', 'max:'.PaginatedList::MAXIMUM_PAGE_SIZE],
            'includeArchived' => ['nullable', 'boolean'],
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
