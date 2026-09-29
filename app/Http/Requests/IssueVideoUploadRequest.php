<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Videos\VideoMessages;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Asking for leave to upload one video: how large it says it is.
 *
 * Only the browser's word, and treated as such — the size that counts is read off the blob when
 * the upload is completed. This is what lets an obviously oversized file be refused before a
 * single byte of it is sent.
 */
final class IssueVideoUploadRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'byteCount' => ['required', 'integer', 'min:1', 'max:'.VideoMessages::maximumBytes()],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['byteCount' => 'bestandsgrootte'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['byteCount.max' => VideoMessages::tooLarge()];
    }

    public function byteCount(): int
    {
        return $this->integer('byteCount');
    }
}
