<?php

declare(strict_types=1);

namespace App\Nova\Breadcrumbs;

use App\Nova\Resource as ContentResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Laravel\Nova\Menu\Breadcrumb;
use Laravel\Nova\Nova;

/**
 * The path from the menu down to a nested record, as the wireframe draws it:
 * Overzichten › E-learnings › {course} › {chapter} › {step}.
 *
 * Each record in the path is named by its title alone and links to its own page. Nova's own crumb
 * for a record reads "Details van Hoofdstuk: …", which is noise four levels deep.
 */
final class ContentTrail
{
    /**
     * Everything above a record: the menu entry its path starts from, then each record it sits
     * inside, outermost first.
     *
     * @param  ContentResource<covariant Model>  $resource
     * @return list<Breadcrumb>
     */
    public static function above(ContentResource $resource): array
    {
        $ancestors = [];

        for ($parent = self::parentOf($resource); $parent !== null; $parent = self::parentOf($parent)) {
            array_unshift($ancestors, $parent);
        }

        $root = $ancestors[0] ?? $resource;

        $crumbs = [
            Breadcrumb::make(Nova::__('Resources')),
            Breadcrumb::resource($root::class),
        ];

        foreach ($ancestors as $ancestor) {
            $crumbs[] = self::linkTo($ancestor);
        }

        return $crumbs;
    }

    /**
     * A record in the path: its title, linking to its page when the operator may open it.
     *
     * @param  ContentResource<covariant Model>  $resource
     */
    public static function linkTo(ContentResource $resource): Breadcrumb
    {
        return Breadcrumb::make($resource->title())
            ->path('/resources/'.$resource::uriKey().'/'.$resource->getKey())
            ->canSee(static fn (Request $request): bool => $resource->authorizedToView($request));
    }

    /**
     * The record a page is about, as the last crumb: named, and not a link to where one already is.
     *
     * @param  ContentResource<covariant Model>  $resource
     */
    public static function current(ContentResource $resource): Breadcrumb
    {
        return Breadcrumb::make($resource->title());
    }

    /**
     * @param  ContentResource<covariant Model>  $resource
     * @return ContentResource<covariant Model>|null
     */
    private static function parentOf(ContentResource $resource): ?ContentResource
    {
        return $resource instanceof NestedResource ? $resource->parentResource() : null;
    }
}
