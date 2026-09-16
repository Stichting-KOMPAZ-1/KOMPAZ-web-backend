<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An administrator's edit.
 *
 * `role` and `organizationId` are optional and mean "leave this alone"; the fields every editor can
 * change are always sent.
 */
final class UpdateUserRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:'.User::MAXIMUM_NAME_LENGTH],
            'email' => ['required', 'string', 'email', 'max:'.User::MAXIMUM_EMAIL_LENGTH],
            'role' => ['nullable', Rule::enum(UserRole::class)],
            'organizationId' => ['nullable', 'uuid'],
        ];
    }

    public function role(): ?UserRole
    {
        $role = $this->validated('role');

        return $role === null ? null : UserRole::from((string) $role);
    }
}
