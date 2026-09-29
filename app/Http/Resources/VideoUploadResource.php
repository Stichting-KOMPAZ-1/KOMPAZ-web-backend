<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\VideoUpload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An upload, as the browser writing it needs to know it.
 *
 * `uploadUrl` and `uploadHeaders` are only present on the answer that issues it: the link is a
 * credential, and nothing else ever hands it out again. It is an Azure blob link that may create
 * one blob, written as a block blob — `PUT {uploadUrl}&comp=block&blockid=…` per block of at most
 * `blockSizeBytes`, then `PUT {uploadUrl}&comp=blocklist` naming them — and then the upload is
 * completed here. `contentType` and `byteCount` are what the bytes turned out to be, once they
 * have been looked at; the key is what a video's `uploadId` names.
 *
 * @mixin VideoUpload
 */
final class VideoUploadResource extends JsonResource
{
    public static $wrap = null;

    /** @param  array<string, string>|null  $uploadHeaders */
    public function __construct(
        VideoUpload $upload,
        private readonly ?string $uploadUrl = null,
        private readonly ?array $uploadHeaders = null,
    ) {
        parent::__construct($upload);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uploadUrl' => $this->when($this->uploadUrl !== null, $this->uploadUrl),
            /** @var array<string, string> */
            'uploadHeaders' => $this->when($this->uploadHeaders !== null, $this->uploadHeaders),
            'blockSizeBytes' => (int) config('kompaz.videos.block_size_bytes'),
            /** @format date-time */
            'expiresUtc' => $this->expires_at->toIso8601String(),
            'isVerified' => $this->isVerified(),
            'contentType' => $this->content_type,
            'byteCount' => $this->byte_count,
        ];
    }
}
