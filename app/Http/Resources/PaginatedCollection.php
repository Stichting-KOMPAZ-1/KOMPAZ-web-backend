<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\Pagination\PaginatedList;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One page of results in the envelope every list endpoint returns.
 *
 * @template TItem of \Illuminate\Database\Eloquent\Model
 */
final readonly class PaginatedCollection implements Responsable
{
    /**
     * @param  PaginatedList<TItem>  $page
     * @param  class-string  $resource
     */
    public function __construct(
        private PaginatedList $page,
        private string $resource,
    ) {}

    public function toResponse($request): JsonResponse
    {
        /** @var Request $request */
        return new JsonResponse([
            'items' => $this->resource::collection($this->page->items)->toArray($request),
            'pageNumber' => $this->page->pageNumber,
            'pageSize' => $this->page->pageSize,
            'totalCount' => $this->page->totalCount,
            'totalPages' => $this->page->totalPages(),
            'hasPreviousPage' => $this->page->hasPreviousPage(),
            'hasNextPage' => $this->page->hasNextPage(),
        ]);
    }
}
