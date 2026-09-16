<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class InviteUserRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:'.User::MAXIMUM_EMAIL_LENGTH],
            'name' => ['required', 'string', 'max:'.User::MAXIMUM_NAME_LENGTH],
            'role' => ['required', Rule::enum(UserRole::class)],
            'organizationId' => ['nullable', 'uuid'],
        ];
    }

    public function role(): UserRole
    {
        return UserRole::from((string) $this->validated('role'));
    }
}
