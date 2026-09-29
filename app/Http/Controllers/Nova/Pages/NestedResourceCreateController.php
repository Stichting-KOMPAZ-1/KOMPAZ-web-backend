<?php

declare(strict_types=1);

namespace App\Http\Controllers\Nova\Pages;

use App\Nova\Breadcrumbs\ContentTrail;
use App\Nova\Breadcrumbs\NestedResource;
use App\Nova\Resource;
use Laravel\Nova\Http\Controllers\Pages\ResourceCreateController;
use Laravel\Nova\Http\Requests\ResourceCreateOrAttachRequest;
use Laravel\Nova\Menu\Breadcrumb;
use Laravel\Nova\Menu\Breadcrumbs;
use Laravel\Nova\Nova;

/**
 * Nova's create page, with the path to the record the new one is being created inside.
 *
 * Only when it is being created from that record's page — "+ Nieuw hoofdstuk" on a course. A
 * create form opened any other way has no parent to show, and keeps Nova's own breadcrumbs.
 */
final class NestedResourceCreateController extends ResourceCreateController
{
    protected function breadcrumbs(ResourceCreateOrAttachRequest $request): Breadcrumbs
    {
        if (! $request->viaRelationship()) {
            return parent::breadcrumbs($request);
        }

        $parent = $request->findParentResourceOrFail();

        if (! $parent instanceof NestedResource || ! $parent instanceof Resource) {
            return parent::breadcrumbs($request);
        }

        return Breadcrumbs::make([
            ...ContentTrail::above($parent),
            ContentTrail::linkTo($parent),
            Breadcrumb::make(Nova::__('Create :resource', ['resource' => $request->resource()::singularLabel()])),
        ]);
    }
}
