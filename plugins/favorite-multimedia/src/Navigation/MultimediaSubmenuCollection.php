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
        if (isset($this->visibleSubmenus[$offset]) || isset($this->hiddenSubmenus[$offset])) {
            return true;
        }
        if (is_string($offset)) {
            $base = explode('?', $offset, 2)[0];
            if (isset($this->visibleSubmenus[$base]) || isset($this->hiddenSubmenus[$base])) {
                return true;
            }
            foreach ($this->visibleSubmenus as $k => $v) {
                if (is_string($k) && explode('?', $k, 2)[0] === $base) {
                    return true;
                }
            }
            foreach ($this->hiddenSubmenus as $k => $v) {
                if (is_string($k) && explode('?', $k, 2)[0] === $base) {
                    return true;
                }
            }
        }
        return false;
    }

    public function offsetGet(mixed $offset): mixed
    {
        if (isset($this->visibleSubmenus[$offset])) {
            return $this->visibleSubmenus[$offset];
        }
        if (isset($this->hiddenSubmenus[$offset])) {
            return $this->hiddenSubmenus[$offset];
        }
        if (is_string($offset)) {
            $base = explode('?', $offset, 2)[0];
            if (isset($this->visibleSubmenus[$base])) {
                return $this->visibleSubmenus[$base];
            }
            if (isset($this->hiddenSubmenus[$base])) {
                return $this->hiddenSubmenus[$base];
            }
            foreach ($this->visibleSubmenus as $k => $v) {
                if (is_string($k) && explode('?', $k, 2)[0] === $base) {
                    return $v;
                }
            }
            foreach ($this->hiddenSubmenus as $k => $v) {
                if (is_string($k) && explode('?', $k, 2)[0] === $base) {
                    return $v;
                }
            }
        }
        return null;
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
        // When iterated in layout.php line 505, line 504 already renders My Submissions as the primary child.
        // Omit multimedia-my-submissions from layout iteration to prevent duplicate "My Submissions" links in the sidebar.
        $iterItems = [];
        foreach ($this->visibleSubmenus as $k => $v) {
            if ($k === 'multimedia-my-submissions' || (is_array($v) && ($v['slug'] ?? '') === 'multimedia-my-submissions')) {
                continue;
            }
            $iterItems[$k] = $v;
        }
        return new ArrayIterator($iterItems);
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

