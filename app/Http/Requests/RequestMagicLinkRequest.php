<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Auth\SameSitePath;
use Illuminate\Foundation\Http\FormRequest;

final class RequestMagicLinkRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            // Where the visitor was going when the login page sent them, so the emailed link can
            // take them there. A path on this site, such as `/e-learnings/…`, never an address.
            'redirect' => ['nullable', 'string', new SameSitePath],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'email' => 'e-mailadres',
            'redirect' => 'pagina',
        ];
    }

    public function email(): string
    {
        return $this->string('email')->toString();
    }

    public function redirect(): ?string
    {
        $redirect = $this->validated('redirect');

        return is_string($redirect) && $redirect !== '' ? $redirect : null;
    }
}
