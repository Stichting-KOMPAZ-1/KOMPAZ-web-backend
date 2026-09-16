<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Shared by refreshing a session and by signing out, which take the same one field. */
final class RefreshTokenRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'refreshToken' => ['required', 'string', 'max:200'],
        ];
    }
}
