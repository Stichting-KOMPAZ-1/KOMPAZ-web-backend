<?php

declare(strict_types=1);

namespace App\Nova;

use App\Providers\NovaServiceProvider;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Laravel\Nova\Resource as NovaResource;

/**
 * The base every Nova resource extends.
 *
 * Nova is the operator's view, and only platform administrators reach it at all — the `viewNova`
 * gate in {@see NovaServiceProvider} settles that once. What it must not become is
 * a second implementation of the product's rules: anything that has to hold true whoever performs
 * it lives in an action under `app/Actions`, and a Nova resource calls that rather than repeating
 * it in a field callback.
 *
 * **The `authorizedTo*` answers are enforced here, not only drawn.** Nova asks them to decide which
 * buttons to show, but the request that reads, edits or replicates one record asks a policy
 * instead — and with no policy, it lets everything through. There are no policies here: which
 * operator may touch which row is answered by the resource. So without the four methods below, a
 * resource that answers "no" to updating only hid the pencil, and a `PUT` typed by hand went
 * straight past it — which is how an organization administrator could overwrite another
 * organization's contact details, and rename a module the platform wrote.
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

    public function authorizeToView(Request $request): void
    {
        throw_unless($this->authorizedToView($request), AuthorizationException::class);

        parent::authorizeToView($request);
    }

    public function authorizeToUpdate(Request $request): void
    {
        throw_unless($this->authorizedToUpdate($request), AuthorizationException::class);

        parent::authorizeToUpdate($request);
    }

    public function authorizeToDelete(Request $request): void
    {
        throw_unless($this->authorizedToDelete($request), AuthorizationException::class);

        parent::authorizeToDelete($request);
    }

    /**
     * Replicating opens a create form filled in from an existing record, so it is a read of that
     * record as much as a creation. Nova answers yes to it outright when there is no policy.
     */
    public function authorizedToReplicate(Request $request): bool
    {
        return static::authorizedToCreate($request)
            && $this->authorizedToView($request)
            && parent::authorizedToReplicate($request);
    }

    public function authorizeToReplicate(Request $request): void
    {
        throw_unless($this->authorizedToReplicate($request), AuthorizationException::class);

        parent::authorizeToReplicate($request);
    }
}
