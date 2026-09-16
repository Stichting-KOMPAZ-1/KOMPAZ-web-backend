<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/** A user's own edit. The name and nothing else. */
final class UpdateOwnProfileRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:'.User::MAXIMUM_NAME_LENGTH],
        ];
    }
}
