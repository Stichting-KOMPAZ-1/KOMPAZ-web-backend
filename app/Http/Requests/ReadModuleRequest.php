<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Access\ModuleAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Reading a module, optionally as one organization.
 *
 * `organizationId` is how a platform administrator sees a module the way that organization does —
 * its contacts, its own videos and links, the links it shares a course by — instead of at no
 * organization. `organization` remains as a compatibility alias. Anybody else may name only their
 * own, which changes nothing; naming another is refused as though the module did not exist
 * ({@see ModuleAccess::resolveActivation()}). The addresses inside a module read this way are under
 * `/organizations/{organization}`, never a query string, because a client appends `?download` to a
 * QR code's.
 */
final class ReadModuleRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // The organization to read the module as. Platform administrators only, for any
            // organization the module is switched on for.
            'organizationId' => ['nullable', 'uuid'],
            'organization' => ['nullable', 'uuid'],
        ];
    }

    public function organizationId(): ?string
    {
        $value = $this->validated('organizationId') ?? $this->validated('organization');

        return is_string($value) && $value !== '' ? $value : null;
    }
}
