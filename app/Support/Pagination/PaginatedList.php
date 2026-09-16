<?php

declare(strict_types=1);

namespace App\Support\Pagination;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * One page of results together with the counters a client needs to render pagination controls.
 *
 * Always a page of models: the page is built from a query, and turning each row into the shape a
 * client sees is the resource's job, one layer out.
 *
 * @template TItem of Model
 */
final readonly class PaginatedList
{
    public const int MAXIMUM_PAGE_SIZE = 100;

    public const int DEFAULT_PAGE_SIZE = 25;

    /** @param Collection<int, TItem> $items */
    public function __construct(
        public Collection $items,
        public int $pageNumber,
        public int $pageSize,
        public int $totalCount,
    ) {}

    /**
     * Counts the source query and materializes the requested page from it.
     *
     * The offset is computed in a way that cannot wrap, because a client picks both numbers:
     * `(pageNumber - 1) * pageSize` overflows a 32-bit integer long before either value is
     * individually unreasonable, and a wrapped offset would quietly serve the wrong page. PHP
     * widens to float rather than wrapping, so a page past the end is answered empty without
     * touching the database instead.
     *
     * @template TModel of Model
     *
     * @param  EloquentBuilder<TModel>  $source
     * @return self<TModel>
     */
    public static function create(EloquentBuilder $source, int $pageNumber, int $pageSize): self
    {
        $totalCount = $source->toBase()->getCountForPagination();
        $skip = ($pageNumber - 1) * $pageSize;

        if ($skip >= $totalCount) {
            // Typed through the builder's own model, so the empty page is the same shape as a full
            // one rather than a collection of nothing in particular.
            return new self($source->getModel()->newCollection(), $pageNumber, $pageSize, $totalCount);
        }

        return new self(
            $source->skip((int) $skip)->take($pageSize)->get(),
            $pageNumber,
            $pageSize,
            $totalCount,
        );
    }

    public function totalPages(): int
    {
        return $this->pageSize <= 0 ? 0 : (int) ceil($this->totalCount / $this->pageSize);
    }

    public function hasPreviousPage(): bool
    {
        return $this->pageNumber > 1;
    }

    public function hasNextPage(): bool
    {
        return $this->pageNumber < $this->totalPages();
    }
}
