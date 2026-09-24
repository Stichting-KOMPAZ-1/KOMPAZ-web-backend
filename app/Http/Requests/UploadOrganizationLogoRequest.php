<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Images\AcceptableLogo;
use App\Support\Organizations\OrganizationMessages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * The logo upload.
 *
 * A missing file is this form's own business; what an acceptable one is belongs to
 * {@see AcceptableLogo}, which the panel's two upload forms apply as well — so one byte too many
 * and a file that is not an image at all are refused in the same words wherever they arrive.
 *
 * The format is checked by reading the bytes, never by believing the upload's `Content-Type` or its
 * file name. The stored value is what a later response is labelled with, so a media type nothing
 * verified would be one an attacker chose.
 */
final class UploadOrganizationLogoRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'logo' => ['required', 'file', new AcceptableLogo],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'logo.required' => OrganizationMessages::LOGO_REQUIRED,
            'logo.file' => OrganizationMessages::LOGO_REQUIRED,
        ];
    }

    /** The bytes that were uploaded, read once the rules above have accepted them. */
    public function contents(): string
    {
        /** @var UploadedFile $file */
        $file = $this->file('logo');

        return (string) file_get_contents($file->getRealPath());
    }
}
