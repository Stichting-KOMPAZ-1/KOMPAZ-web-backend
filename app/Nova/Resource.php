<?php

declare(strict_types=1);

namespace App\Nova;

use App\Providers\NovaServiceProvider;
use Laravel\Nova\Resource as NovaResource;

/**
 * The base every Nova resource extends.
 *
 * Nova is the operator's view, and only platform administrators reach it at all — the `viewNova`
 * gate in {@see NovaServiceProvider} settles that once. What it must not become is
 * a second implementation of the product's rules: anything that has to hold true whoever performs
 * it lives in an action under `app/Actions`, and a Nova resource calls that rather than repeating
 * it in a field callback.
 */
/**
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends NovaResource<TModel>
 */
abstract class Resource extends NovaResource
{
    /**
     * Nova sorts by the model's key by default, which for a UUIDv7 is creation order rather than
     * anything an operator recognizes. Every resource here names its own order instead.
     */
    public static $perPageOptions = [25, 50, 100];
}
