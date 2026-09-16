<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Images\LogoImage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

/**
 * The logo upload.
 *
 * Both of the upload's own rejections are stated here, so the API answers them the same way whether
 * the caller sent one byte too many or a file that is not an image at all: a 400 naming the field.
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
            'logo' => ['required', 'file'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'logo.required' => 'Kies een logo om te uploaden.',
            'logo.file' => 'Kies een logo om te uploaden.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $file = $this->file('logo');

            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                return;
            }

            if (LogoImage::exceedsMaximumSize($file->getSize() ?: 0)) {
                $validator->errors()->add('logo', sprintf(
                    'Upload een kleiner bestand van maximaal %s.',
                    LogoImage::MAXIMUM_SIZE,
                ));

                return;
            }

            if (LogoImage::detectContentType((string) file_get_contents($file->getRealPath())) === null) {
                $validator->errors()->add('logo', sprintf(
                    'Upload een afbeelding van het type %s.',
                    LogoImage::ACCEPTED_FORMATS,
                ));
            }
        });
    }

    /** The bytes that were uploaded, read once the rules above have accepted them. */
    public function contents(): string
    {
        /** @var UploadedFile $file */
        $file = $this->file('logo');

        return (string) file_get_contents($file->getRealPath());
    }
}
