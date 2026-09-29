<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Images\AcceptableLogo;
use App\Support\Modules\ContentRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use LogicException;

/**
 * A picture for a module or a course, on its own endpoint so the rest of their bodies stay JSON.
 *
 * Multipart, and so sent as a POST carrying `_method=PUT`: PHP reads no files out of a real PUT.
 * What an acceptable picture is — its size, and a format read out of its bytes — is
 * {@see AcceptableLogo}'s, the same rule the panel's forms ask.
 */
final class UploadContentImageRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'image' => ContentRules::requiredImage(),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['image' => 'afbeelding'];
    }

    public function picture(): UploadedFile
    {
        $image = $this->file('image');

        if (! $image instanceof UploadedFile) {
            throw new LogicException('The image rule let a request through without a file.');
        }

        return $image;
    }
}
