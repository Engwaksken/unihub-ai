<?php

namespace App\Gateway;

/**
 * One page of a cursor-paginated list: {"data": [...], "next_cursor": "..."|null}.
 *
 * @template T
 */
final class GatewayPage
{
    /**
     * @param  list<T>  $items
     */
    public function __construct(
        public readonly array $items,
        public readonly ?string $nextCursor,
    ) {}

    /**
     * @template R
     *
     * @param  callable(T): R  $callback
     * @return GatewayPage<R>
     */
    public function map(callable $callback): self
    {
        return new self(array_map($callback, $this->items), $this->nextCursor);
    }

    /**
     * @param  callable(T): bool  $callback
     * @return GatewayPage<T>
     */
    public function filter(callable $callback): self
    {
        return new self(array_values(array_filter($this->items, $callback)), $this->nextCursor);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }
}
