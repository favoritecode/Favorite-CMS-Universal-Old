<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Navigation;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Creator-scoped submenus collection.
 *
 * Implements ArrayAccess and IteratorAggregate so that:
 * 1. AdminMenu::findPage($slug) can find both visible and hidden route handlers via offsetExists() / offsetGet().
 * 2. Favorite CMS admin layout (resources/views/admin/layout.php) only iterates over visible creator submenus
 *    (My Submissions, Add Movie, Add Series, Add Song, Add Album, Add Playlist).
 */
class MultimediaSubmenuCollection implements ArrayAccess, IteratorAggregate, Countable
{
    /**
     * @var array<string, array>
     */
    private array $visibleSubmenus;

    /**
     * @var array<string, array>
     */
    private array $hiddenSubmenus;

    public function __construct(array $visibleSubmenus = [], array $hiddenSubmenus = [])
    {
        $this->visibleSubmenus = $visibleSubmenus;
        $this->hiddenSubmenus = $hiddenSubmenus;
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->visibleSubmenus[$offset]) || isset($this->hiddenSubmenus[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->visibleSubmenus[$offset] ?? $this->hiddenSubmenus[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->visibleSubmenus[] = $value;
        } else {
            $this->visibleSubmenus[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->visibleSubmenus[$offset], $this->hiddenSubmenus[$offset]);
    }

    public function count(): int
    {
        return count($this->visibleSubmenus);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->visibleSubmenus);
    }

    public function getVisible(): array
    {
        return $this->visibleSubmenus;
    }

    public function getHidden(): array
    {
        return $this->hiddenSubmenus;
    }

    public function getAll(): array
    {
        return array_merge($this->hiddenSubmenus, $this->visibleSubmenus);
    }

    public function toArray(): array
    {
        return $this->visibleSubmenus;
    }
}

