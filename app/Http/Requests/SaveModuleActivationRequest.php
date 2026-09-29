<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReadsContentLists;
use App\Support\Modules\ActivationDetails;
use Illuminate\Foundation\Http\FormRequest;

/**
 * What an organization adds to its own copy of a module. Each list may be left out, which leaves
 * it as it is.
 */
final class SaveModuleActivationRequest extends FormRequest
{
    use ReadsContentLists;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...self::videoRules(),
            ...self::linkRules(),
            ...self::contactRules(),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return self::listAttributes();
    }

    public function details(): ActivationDetails
    {
        return new ActivationDetails(
            videos: $this->videoList(),
            links: $this->linkList('links'),
            contacts: $this->contactList(),
        );
    }
}
