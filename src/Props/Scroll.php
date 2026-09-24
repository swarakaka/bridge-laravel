<?php

declare(strict_types=1);

namespace Bridge\Props;

use Illuminate\Contracts\Pagination\Paginator as PaginatorContract;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\AbstractCursorPaginator;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Support\Arr;
use InvalidArgumentException;

/**
 * An infinite-scroll prop (PLAN §13.4): a paginator, a cursor paginator or a
 * resource collection over one, merged by appending, with `meta.scroll`
 * describing both ends so the client never parses `links`. Items are matched
 * on `data.id` when every item on the page has an `id`.
 */
final class Scroll extends PropHint
{
    private bool $explicitMatch = false;

    public function __construct(mixed $value, public readonly ?string $pageName = null)
    {
        if ($value instanceof PropHint) {
            throw new InvalidArgumentException('Bridge::scroll() cannot wrap another prop hint.');
        }

        parent::__construct($value);
        $this->initMerge(new MergeOptions(MergeOptions::APPEND));
    }

    /** Overrides the default `data.id` match path; no paths turns matching off. */
    public function matchOn(string ...$paths): static
    {
        $copy = parent::matchOn(...$paths);
        $copy->explicitMatch = true;

        return $copy;
    }

    /**
     * `meta.scroll` for the resolved value, and the match paths to use.
     *
     * @return array{scroll: array{pageName: string, dataPath: string, currentPage: int|string|null, previousPage: int|string|null, nextPage: int|string|null}, matchOn: list<string>}
     */
    public function describe(string $prop, mixed $resolved): array
    {
        $paginator = $resolved instanceof ResourceCollection ? $resolved->resource : $resolved;

        if ($paginator instanceof AbstractCursorPaginator) {
            $scroll = [
                'pageName' => $this->pageName ?? $paginator->getCursorName(),
                'dataPath' => 'data',
                'currentPage' => $paginator->cursor()?->encode(),
                'previousPage' => $paginator->previousCursor()?->encode(),
                'nextPage' => $paginator->nextCursor()?->encode(),
            ];
        } elseif ($paginator instanceof AbstractPaginator) {
            $current = $paginator->currentPage();
            $hasMore = $paginator instanceof PaginatorContract && $paginator->hasMorePages();

            $scroll = [
                'pageName' => $this->pageName ?? $paginator->getPageName(),
                'dataPath' => 'data',
                'currentPage' => $current,
                'previousPage' => $current > 1 ? $current - 1 : null,
                'nextPage' => $hasMore ? $current + 1 : null,
            ];
        } else {
            throw new InvalidArgumentException("Prop [{$prop}]: Bridge::scroll() needs a paginator, a cursor paginator or a resource collection over one.");
        }

        if ($this->explicitMatch) {
            $matchOn = $this->mergeOptions()->matchOn ?? [];
        } else {
            $items = $paginator->items();
            $matchOn = $items !== [] && Arr::every($items, static fn (mixed $item): bool => data_get($item, 'id') !== null) ? ['data.id'] : [];
        }

        return ['scroll' => $scroll, 'matchOn' => $matchOn];
    }
}
