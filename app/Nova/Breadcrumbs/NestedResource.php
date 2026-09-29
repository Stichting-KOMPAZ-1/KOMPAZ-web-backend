<?php

declare(strict_types=1);

namespace App\Nova\Breadcrumbs;

use App\Nova\Resource as ContentResource;
use Illuminate\Database\Eloquent\Model;

/**
 * A resource that is read inside another one: a step inside its chapter, a chapter inside its
 * course.
 *
 * Nova's breadcrumbs know one level — the resource's own index and the record — so a step read
 * "Stappen › Stap: …", naming a list nobody navigates to and leaving out the course and chapter it
 * belongs to. A resource that says what it sits under gets the whole path instead; see
 * {@see ContentTrail}.
 */
interface NestedResource
{
    /**
     * The record this one is read inside, or null for the top of the path.
     *
     * @return ContentResource<covariant Model>|null
     */
    public function parentResource(): ?ContentResource;
}
