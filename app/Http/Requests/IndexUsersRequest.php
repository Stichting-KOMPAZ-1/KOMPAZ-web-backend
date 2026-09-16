<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\UserStatus;
use App\Support\Pagination\PaginatedList;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexUsersRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(UserStatus::class)],
            'search' => ['nullable', 'string', 'max:320'],
            'organizationId' => ['nullable', 'uuid'],
            'includeDeleted' => ['nullable', 'boolean'],
            'pageNumber' => ['nullable', 'integer', 'min:1'],
            'pageSize' => ['nullable', 'integer', 'min:1', 'max:'.PaginatedList::MAXIMUM_PAGE_SIZE],
        ];
    }

    public function status(): ?UserStatus
    {
        $status = $this->validated('status');

        return $status === null ? null : UserStatus::from((string) $status);
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
