<?php

declare(strict_types=1);

namespace App\Nova;

use App\Providers\NovaServiceProvider;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\FieldCollection;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Nova;
use Laravel\Nova\Panel;
use Laravel\Nova\Resource as NovaResource;

/**
 * The base every Nova resource extends.
 *
 * Nova is the operator's view, and only administrators reach it at all — the `viewNova` gate in
 * {@see NovaServiceProvider} settles that once, and each resource scopes what an organization
 * administrator sees to their own tenant. What it must not become is
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

    /**
     * What the edit page calls itself, in its heading and its last breadcrumb.
     *
     * Nova's own words by default — ":resource opslaan" — and a resource whose form is for something
     * narrower than editing says so instead: an organization's copy of a module is where its own
     * information is added, not where the module is changed (KOM-53).
     */
    public static function updatePageLabel(): string
    {
        return (string) Nova::__('Update :resource', ['resource' => static::singularLabel()]);
    }

    /**
     * Nova's, with the heading taken from {@see updatePageLabel()}. For every resource that keeps
     * the default this is Nova's own text, ":resource opslaan: :title".
     *
     * @param  NovaResource<covariant Model>|null  $resource
     * @return FieldCollection<int, Field>
     */
    #[\Override]
    public function updateFieldsWithinPanels(NovaRequest $request, ?NovaResource $resource = null): FieldCollection
    {
        return $this->updateFields($request)
            ->assignDefaultPanel(self::updatePageHeading($resource ?? $request->newResource()));
    }

    /**
     * Nova's, with the heading taken from {@see updatePageLabel()}, for the reason above.
     *
     * @param  NovaResource<covariant Model>|null  $resource
     * @param  FieldCollection<int, Field>|null  $fields
     * @return array<int, Panel>
     */
    #[\Override]
    public function availablePanelsForUpdate(NovaRequest $request, ?NovaResource $resource = null, ?FieldCollection $fields = null): array
    {
        $method = $this->fieldsMethod($request);

        $fields ??= FieldCollection::make(array_values($this->{$method}($request)))
            ->onlyUpdateFields($request, $this->resource);

        return $this->resolvePanelsFromFields(
            $request,
            $fields,
            self::updatePageHeading($resource ?? $request->newResource()),
        )->all();
    }

    /** @param  NovaResource<covariant Model>  $resource */
    private static function updatePageHeading(NovaResource $resource): string
    {
        $label = $resource instanceof self
            ? $resource::updatePageLabel()
            : (string) Nova::__('Update :resource', ['resource' => $resource::singularLabel()]);

        return $label.': '.$resource->title();
    }

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

    /**
     * What a table is told about editing each row: never, so Nova draws no pencil on any table.
     *
     * The product wants a table's operations in the row's "…" menu and nowhere else (KOM-42), and
     * the pencil is drawn from this answer alone. Only the answer *serialized into a listing* is
     * no: the edit form, the detail page's own edit button and {@see authorizeToUpdate()} ask
     * {@see authorizedToUpdate()} itself, so nothing anybody may edit becomes uneditable. A resource
     * whose rows can be edited offers {@see Actions\EditResource} in the menu instead.
     */
    #[\Override]
    protected function authorizedToUpdateForSerialization(NovaRequest $request): bool
    {
        if ($request->isResourceIndexRequest()) {
            return false;
        }

        return parent::authorizedToUpdateForSerialization($request);
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
