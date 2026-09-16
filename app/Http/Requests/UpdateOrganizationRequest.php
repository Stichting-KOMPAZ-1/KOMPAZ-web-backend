<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Organization;
use App\Support\Organizations\OrganizationMessages;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateOrganizationRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:'.Organization::MAXIMUM_NAME_LENGTH],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => OrganizationMessages::NAME_REQUIRED,
            'name.max' => OrganizationMessages::nameTooLong(Organization::MAXIMUM_NAME_LENGTH),
        ];
    }
}
