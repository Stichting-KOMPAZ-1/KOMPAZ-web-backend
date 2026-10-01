<?php

declare(strict_types=1);

namespace App\Nova\Concerns;

use App\Support\Files\StoredImage;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Laravel\Nova\Support\Fluent;

/**
 * Storing a picture the way rule 12 requires, for the content resources that carry one.
 *
 * Two resources upload an image and a third will, and the part that must not be retyped is the
 * part that is easy to get subtly wrong: **the media type is read out of the bytes**, never taken
 * from the upload's own `Content-Type` or its file name. The stored value is what a later response
 * is labelled with, so believing the caller would let them choose how their bytes are handed back.
 *
 * The key is minted from the record's own identifier and a fresh one, never accepted from the
 * form, so nothing an upload says can reach another record's file.
 */
trait StoresUploadedImage
{
    /**
     * A `store()` callback that writes the bytes and returns the three columns that name them.
     *
     * The bytes are written *before* the row that points at them, which is the ordering rule 13
     * turns on: a failure between the two leaves an orphaned file rather than a row pointing at
     * nothing. Never the other way round.
     *
     * @param  string  $prefix  where this kind of record keeps its pictures, under the disk's root
     * @param  string  $column  the name the other two columns share: a module's picture is
     *                          `image_*`, a content block's file is `file_*`
     */
    protected function storesImageUnder(string $prefix, string $column = 'image'): Closure
    {
        return function (Request $request, Model|Fluent $model, string $attribute, string $requestAttribute) use ($prefix, $column): array {
            $upload = $request->file($requestAttribute);

            if (! $upload instanceof UploadedFile) {
                return [];
            }

            $image = StoredImage::store($upload, $prefix, self::ownerIdentifier($model));

            return [
                $attribute => $image->key,
                $column.'_content_type' => $image->contentType,
                $column.'_byte_count' => $image->byteCount,
            ];
        };
    }

    /**
     * The identifier the key is filed under.
     *
     * On an edit the record already has one. On a create it does not yet — Nova fills the fields
     * before the insert, and `HasUuids` mints the key during it — so one is minted here and set on
     * the model, which `HasUuids` then leaves alone. That keeps every file under its record's own
     * folder rather than giving the first upload of a record's life a home of its own.
     */
    private static function ownerIdentifier(Model|Fluent $model): string
    {
        if ($model instanceof Model) {
            $existing = $model->getKey();

            if (is_string($existing) && $existing !== '') {
                return $existing;
            }

            $minted = (string) Str::orderedUuid();
            $model->setAttribute('id', $minted);

            return $minted;
        }

        return (string) Str::orderedUuid();
    }
}
