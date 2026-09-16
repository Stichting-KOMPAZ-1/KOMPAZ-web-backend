<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\DataObjects\AuthenticationResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AuthenticationResult */
final class AuthenticationResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var AuthenticationResult $result */
        $result = $this->resource;

        return [
            'token' => $result->token,
            'tokenType' => 'Bearer',
            'user' => UserResource::make($result->user->loadMissing(['organization', 'outstandingInvitations'])),
        ];
    }
}
