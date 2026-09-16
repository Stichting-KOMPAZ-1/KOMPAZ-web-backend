<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\DataObjects\AuthenticationResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A signed-in session: a short-lived bearer token, the refresh token that renews it, and the
 * profile they belong to.
 *
 * @mixin AuthenticationResult
 */
final class AuthenticationResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var AuthenticationResult $result */
        $result = $this->resource;

        return [
            'accessToken' => $result->accessToken->value,
            'tokenType' => 'Bearer',
            'expiresUtc' => $result->accessToken->expiresAt->toIso8601String(),
            'refreshToken' => $result->refreshToken->value,
            'refreshTokenExpiresUtc' => $result->refreshToken->expiresAt->toIso8601String(),
            'user' => UserResource::make($result->user->loadMissing('organization')),
        ];
    }
}
