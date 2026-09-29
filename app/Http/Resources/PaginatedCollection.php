<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\Pagination\PaginatedList;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * One page of results in the envelope every list endpoint returns.
 *
 * The rows arrive already shaped, as `SomeResource::collection(...)`, rather than as a class name
 * this envelope instantiates. Scramble infers the response from this method's body, and a resource
 * named by a string is one it cannot follow: `items` was documented as a string on every listing.
 * The counters are read through {@see PaginatedList}'s methods for the same reason.
 *
 * @template TItem of Model
 */
final readonly class PaginatedCollection implements Responsable
{
    /** @param  PaginatedList<TItem>  $page */
    public function __construct(
        private PaginatedList $page,
        private AnonymousResourceCollection $items,
    ) {}

    public function toResponse($request): JsonResponse
    {
        /** @var Request $request */
        return new JsonResponse([
            'items' => $this->items,
            'pageNumber' => $this->page->pageNumber(),
            'pageSize' => $this->page->pageSize(),
            'totalCount' => $this->page->totalCount(),
            'totalPages' => $this->page->totalPages(),
            'hasPreviousPage' => $this->page->hasPreviousPage(),
            'hasNextPage' => $this->page->hasNextPage(),
        ]);
    }
}
