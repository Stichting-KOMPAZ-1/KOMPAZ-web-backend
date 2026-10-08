<?php

declare(strict_types=1);

namespace App\Http\Controllers\Nova\Pages;

use App\Nova\Breadcrumbs\ContentTrail;
use App\Nova\Breadcrumbs\NestedResource;
use App\Nova\Resource as PanelResource;
use Illuminate\Database\Eloquent\Model;
use Laravel\Nova\Http\Controllers\Pages\ResourceUpdateController;
use Laravel\Nova\Http\Requests\ResourceUpdateOrUpdateAttachedRequest;
use Laravel\Nova\Menu\Breadcrumb;
use Laravel\Nova\Menu\Breadcrumbs;
use Laravel\Nova\Nova;

/**
 * Nova's edit page, with the path to a nested record above the form, and the page's own name —
 * {@see PanelResource::updatePageLabel()} — as its last crumb rather than Nova's ":resource opslaan".
 */
final class NestedResourceUpdateController extends ResourceUpdateController
{
    protected function breadcrumbs(ResourceUpdateOrUpdateAttachedRequest $request): Breadcrumbs
    {
        $resource = $request->findResourceOrFail();

        if (! $resource instanceof PanelResource) {
            return parent::breadcrumbs($request);
        }

        $resource->authorizeToUpdate($request);

        $trail = $resource instanceof NestedResource
            ? [...ContentTrail::above($resource), ContentTrail::linkTo($resource)]
            : self::nonNestedTrail($request, $resource);

        return Breadcrumbs::make([...$trail, Breadcrumb::make($resource::updatePageLabel())]);
    }

    /**
     * What Nova puts above the last crumb for a record that is not nested: the resource's list and
     * the record, or the record it was reached through and that record.
     *
     * @param  PanelResource<covariant Model>  $resource
     * @return list<Breadcrumb>
     */
    private static function nonNestedTrail(ResourceUpdateOrUpdateAttachedRequest $request, PanelResource $resource): array
    {
        if ($request->viaRelationship()) {
            return [
                Breadcrumb::make(Nova::__('Resources')),
                Breadcrumb::resource($request->viaResource()),
                Breadcrumb::resource($request->findParentResource()),
            ];
        }

        return [
            Breadcrumb::make(Nova::__('Resources')),
            Breadcrumb::resource($resource::class),
            Breadcrumb::resource($resource),
        ];
    }
}
