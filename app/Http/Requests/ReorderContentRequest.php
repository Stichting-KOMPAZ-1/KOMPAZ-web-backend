<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** A course's chapters, or a chapter's parts, in their new order. */
final class ReorderContentRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'list'],
            'ids.*' => ['required', 'uuid'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['ids' => 'volgorde'];
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_values(array_filter($this->array('ids'), 'is_string'));
    }
}
