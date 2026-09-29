<?php

declare(strict_types=1);

namespace App\Http\Controllers\Nova\Pages;

use App\Nova\Breadcrumbs\ContentTrail;
use App\Nova\Breadcrumbs\NestedResource;
use App\Nova\Resource;
use Laravel\Nova\Http\Controllers\Pages\ResourceUpdateController;
use Laravel\Nova\Http\Requests\ResourceUpdateOrUpdateAttachedRequest;
use Laravel\Nova\Menu\Breadcrumb;
use Laravel\Nova\Menu\Breadcrumbs;
use Laravel\Nova\Nova;

/** Nova's edit page, with the path to a nested record above the form. */
final class NestedResourceUpdateController extends ResourceUpdateController
{
    protected function breadcrumbs(ResourceUpdateOrUpdateAttachedRequest $request): Breadcrumbs
    {
        $resource = $request->findResourceOrFail();

        if (! $resource instanceof NestedResource || ! $resource instanceof Resource) {
            return parent::breadcrumbs($request);
        }

        $resource->authorizeToUpdate($request);

        return Breadcrumbs::make([
            ...ContentTrail::above($resource),
            ContentTrail::linkTo($resource),
            Breadcrumb::make(Nova::__('Update :resource', ['resource' => $resource::singularLabel()])),
        ]);
    }
}
