<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Theme\Search;

/**
 * Value object representing a categorized group of search results.
 */
final class SearchResultGroup
{
    private string $key;
    private string $title;
    private string $icon;
    private array $items;
    private int $totalCount;

    public function __construct(string $key, string $title, string $icon, array $items = [], ?int $totalCount = null)
    {
        $this->key = $key;
        $this->title = $title;
        $this->icon = $icon;
        $this->items = array_values($items);
        $this->totalCount = $totalCount ?? count($this->items);
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getIcon(): string
    {
        return $this->icon;
    }

    public function getItems(): array
    {
        return $this->items;
    }

    public function getCount(): int
    {
        return count($this->items);
    }

    public function getTotalCount(): int
    {
        return $this->totalCount;
    }

    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    public function toArray(): array
    {
        return [
            'key'         => $this->key,
            'title'       => $this->title,
            'icon'        => $this->icon,
            'items'       => $this->items,
            'count'       => count($this->items),
            'total_count' => $this->totalCount,
        ];
    }
}
