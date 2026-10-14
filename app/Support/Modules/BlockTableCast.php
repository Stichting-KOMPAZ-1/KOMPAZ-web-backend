<?php

declare(strict_types=1);

namespace App\Support\Modules;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use JsonException;

/**
 * A table block's column: JSON in the row, a {@see BlockTable} on the model.
 *
 * Only a {@see BlockTable} or null is ever assigned, which is what the generic below states; the
 * forms turn what they were sent into one first, so the row only ever holds the one shape.
 *
 * @implements CastsAttributes<BlockTable|null, BlockTable|null>
 */
final class BlockTableCast implements CastsAttributes
{
    /** @param  array<string, mixed>  $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?BlockTable
    {
        return is_string($value) ? BlockTable::fromInput($value) : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws JsonException
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : json_encode($value->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
