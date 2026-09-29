<?php

declare(strict_types=1);

namespace App\Http\Controllers\Nova\Pages;

use App\Nova\Breadcrumbs\ContentTrail;
use App\Nova\Breadcrumbs\NestedResource;
use App\Nova\Resource;
use App\Providers\NovaServiceProvider;
use Laravel\Nova\Http\Controllers\Pages\ResourceDetailController;
use Laravel\Nova\Http\Requests\ResourceDetailRequest;
use Laravel\Nova\Menu\Breadcrumbs;

/**
 * Nova's detail page, with the whole path above a nested record in its breadcrumbs.
 *
 * Bound in place of Nova's own in {@see NovaServiceProvider}. Every other resource
 * gets Nova's breadcrumbs untouched.
 */
final class NestedResourceDetailController extends ResourceDetailController
{
    protected function breadcrumbs(ResourceDetailRequest $request): Breadcrumbs
    {
        $resource = $request->findResourceOrFail();

        if (! $resource instanceof NestedResource || ! $resource instanceof Resource) {
            return parent::breadcrumbs($request);
        }

        $resource->authorizeToView($request);

        return Breadcrumbs::make([...ContentTrail::above($resource), ContentTrail::current($resource)]);
    }
}
