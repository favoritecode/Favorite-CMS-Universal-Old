<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Theme\Audio;

/**
 * Manages audio playback queue, ordering, repeat, and shuffle determinism.
 * Never stores or manipulates protected stream URLs.
 */
final class AudioQueueManager
{
    /** @var array<int, array<string, mixed>> */
    private array $items = [];

    /** @var array<int, array<string, mixed>> Stores canonical order when shuffle is enabled */
    private array $originalOrder = [];

    private int $currentIndex = -1;
    private string $repeatMode = AudioPlayerState::REPEAT_OFF;
    private bool $isShuffled = false;

    /**
     * @param array<int, array<string, mixed>> $items
     */
    public function __construct(
        array $items = [],
        int $currentIndex = -1,
        string $repeatMode = AudioPlayerState::REPEAT_OFF,
        bool $isShuffled = false
    ) {
        $this->setQueue($items, $currentIndex);
        $this->setRepeatMode($repeatMode);
        $this->isShuffled = $isShuffled;
        if ($isShuffled && !empty($this->items)) {
            $this->originalOrder = $this->items;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    public function getCurrentIndex(): int
    {
        return $this->currentIndex;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCurrentItem(): ?array
    {
        if ($this->currentIndex >= 0 && isset($this->items[$this->currentIndex])) {
            return $this->items[$this->currentIndex];
        }
        return null;
    }

    public function getRepeatMode(): string
    {
        return $this->repeatMode;
    }

    public function setRepeatMode(string $mode): void
    {
        if (in_array($mode, [AudioPlayerState::REPEAT_OFF, AudioPlayerState::REPEAT_QUEUE, AudioPlayerState::REPEAT_ONE], true)) {
            $this->repeatMode = $mode;
        }
    }

    public function isShuffled(): bool
    {
        return $this->isShuffled;
    }

    /**
     * Sets queue items and starting position.
     *
     * @param array<int, array<string, mixed>> $items
     */
    public function setQueue(array $items, int $startIndex = 0): void
    {
        $sanitized = [];
        foreach ($items as $idx => $raw) {
            $sanitized[] = $this->sanitizeItem($raw, $idx);
        }
        $this->items = array_values($sanitized);
        $this->originalOrder = $this->items;
        $this->isShuffled = false;

        if (empty($this->items)) {
            $this->currentIndex = -1;
        } else {
            $this->currentIndex = max(0, min(count($this->items) - 1, $startIndex));
        }
    }

    /**
     * Inserts an item immediately after current index to play next.
     *
     * @param array<string, mixed> $item
     * @return int Inserted index
     */
    public function playNext(array $item): int
    {
        $clean = $this->sanitizeItem($item, count($this->items));
        if ($this->isEmpty() || $this->currentIndex < 0) {
            $this->items = [$clean];
            $this->currentIndex = 0;
            $this->originalOrder = $this->items;
            return 0;
        }

        $insertPos = $this->currentIndex + 1;
        array_splice($this->items, $insertPos, 0, [$clean]);
        if ($this->isShuffled) {
            $this->originalOrder[] = $clean;
        }
        return $insertPos;
    }

    /**
     * Alias for playNext.
     *
     * @param array<string, mixed> $item
     * @return int
     */
    public function insertNext(array $item): int
    {
        return $this->playNext($item);
    }

    /**
     * Appends an item to the end of the queue.
     *
     * @param array<string, mixed> $item
     */
    public function addToQueue(array $item): void
    {
        $clean = $this->sanitizeItem($item, count($this->items));
        if ($this->isEmpty()) {
            $this->items = [$clean];
            $this->currentIndex = 0;
            $this->originalOrder = $this->items;
            return;
        }

        $this->items[] = $clean;
        if ($this->isShuffled) {
            $this->originalOrder[] = $clean;
        }
    }

    /**
     * Alias for addToQueue.
     *
     * @param array<string, mixed> $item
     */
    public function add(array $item): void
    {
        $this->addToQueue($item);
    }

    /**
     * Removes item at index.
     *
     * @return array<string, mixed>|null
     */
    public function removeAt(int $index): ?array
    {
        if (!isset($this->items[$index])) {
            return null;
        }

        $removed = $this->items[$index];
        array_splice($this->items, $index, 1);

        if (empty($this->items)) {
            $this->currentIndex = -1;
        } elseif ($index < $this->currentIndex) {
            $this->currentIndex--;
        } elseif ($index === $this->currentIndex && $this->currentIndex >= count($this->items)) {
            $this->currentIndex = count($this->items) - 1;
        }

        return $removed;
    }

    /**
     * Alias for removeAt.
     *
     * @return array<string, mixed>|null
     */
    public function remove(int $index): ?array
    {
        return $this->removeAt($index);
    }

    /**
     * Moves an item from fromIndex to toIndex.
     */
    public function move(int $fromIndex, int $toIndex): void
    {
        if (!isset($this->items[$fromIndex])) {
            return;
        }

        $item = $this->items[$fromIndex];
        $isCurrent = ($fromIndex === $this->currentIndex);

        array_splice($this->items, $fromIndex, 1);
        $target = max(0, min(count($this->items), $toIndex));
        array_splice($this->items, $target, 0, [$item]);

        if ($isCurrent) {
            $this->currentIndex = $target;
        } elseif ($fromIndex < $this->currentIndex && $target >= $this->currentIndex) {
            $this->currentIndex--;
        } elseif ($fromIndex > $this->currentIndex && $target <= $this->currentIndex) {
            $this->currentIndex++;
        }
    }

    public function clear(bool $preserveActive = false): void
    {
        if ($preserveActive && $this->getCurrentItem() !== null) {
            $active = $this->getCurrentItem();
            $this->items = [$active];
            $this->originalOrder = [$active];
            $this->currentIndex = 0;
            $this->isShuffled = false;
            return;
        }

        $this->items = [];
        $this->originalOrder = [];
        $this->currentIndex = -1;
        $this->isShuffled = false;
    }

    /**
     * Advances to the next queue item.
     *
     * @return array<string, mixed>|null
     */
    public function next(): ?array
    {
        if ($this->isEmpty()) {
            return null;
        }

        if ($this->repeatMode === AudioPlayerState::REPEAT_ONE) {
            return $this->getCurrentItem();
        }

        $nextIndex = $this->currentIndex + 1;
        if ($nextIndex >= count($this->items)) {
            if ($this->repeatMode === AudioPlayerState::REPEAT_QUEUE) {
                $this->currentIndex = 0;
                return $this->getCurrentItem();
            }
            return null; // Reached end of queue with repeat off
        }

        $this->currentIndex = $nextIndex;
        return $this->getCurrentItem();
    }

    /**
     * Moves to previous track, or restarts current track if elapsed time > threshold.
     *
     * @return array{item: array<string, mixed>|null, action: 'restart'|'previous'}
     */
    public function previous(float $currentTime = 0.0, float $threshold = 3.0): array
    {
        if ($this->isEmpty()) {
            return ['item' => null, 'action' => 'none'];
        }

        if ($currentTime > $threshold) {
            return ['item' => $this->getCurrentItem(), 'action' => 'restart'];
        }

        if ($this->currentIndex > 0) {
            $this->currentIndex--;
            return ['item' => $this->getCurrentItem(), 'action' => 'previous'];
        }

        if ($this->repeatMode === AudioPlayerState::REPEAT_QUEUE) {
            $this->currentIndex = count($this->items) - 1;
            return ['item' => $this->getCurrentItem(), 'action' => 'previous'];
        }

        return ['item' => $this->getCurrentItem(), 'action' => 'restart'];
    }

    /**
     * Toggles deterministic shuffle:
     * - Preserves currently playing track at index 0.
     * - Restores original canonical order when shuffle is disabled.
     */
    public function toggleShuffle(): bool
    {
        if (count($this->items) <= 1) {
            $this->isShuffled = !$this->isShuffled;
            return $this->isShuffled;
        }

        if ($this->isShuffled) {
            // Restore original canonical order
            $currentId = $this->getCurrentItem()['id'] ?? null;
            $this->items = $this->originalOrder;
            $this->isShuffled = false;
            if ($currentId !== null) {
                foreach ($this->items as $idx => $it) {
                    if ((int)$it['id'] === (int)$currentId) {
                        $this->currentIndex = $idx;
                        break;
                    }
                }
            }
        } else {
            // Shuffle subsequent items while preserving current item
            $this->originalOrder = $this->items;
            $currentItem = $this->getCurrentItem();
            $remaining = [];
            foreach ($this->items as $idx => $it) {
                if ($idx !== $this->currentIndex) {
                    $remaining[] = $it;
                }
            }
            shuffle($remaining);
            $this->items = array_merge([$currentItem], $remaining);
            $this->currentIndex = 0;
            $this->isShuffled = true;
        }

        return $this->isShuffled;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function sanitizeItem(array $item, int $index): array
    {
        return [
            'id'           => (int)($item['id'] ?? 0),
            'content_type' => 'song',
            'title'        => (string)($item['title'] ?? 'Untitled Track'),
            'artist'       => (string)($item['artist'] ?? $item['artist_name'] ?? 'Unknown Artist'),
            'cover'        => (string)($item['cover'] ?? $item['artwork'] ?? ''),
            'duration'     => (int)($item['duration'] ?? 0),
            'access_mode'  => (string)($item['access_mode'] ?? 'public'),
            'album_id'     => isset($item['album_id']) ? (int)$item['album_id'] : null,
            'artist_id'    => isset($item['artist_id']) ? (int)$item['artist_id'] : null,
            'sort_order'   => (int)($item['sort_order'] ?? $index),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'items'          => $this->items,
            'current_index'  => $this->currentIndex,
            'repeat_mode'    => $this->repeatMode,
            'is_shuffled'    => $this->isShuffled,
            'original_order' => $this->originalOrder,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $rawItems = $data['items'] ?? $data['queue'] ?? [];
        $rawIdx = $data['current_index'] ?? $data['currentIndex'] ?? -1;
        $rawRepeat = $data['repeat_mode'] ?? $data['repeatMode'] ?? AudioPlayerState::REPEAT_OFF;
        $rawShuffled = !empty($data['is_shuffled']) || !empty($data['isShuffled']);

        $manager = new self(
            (array)$rawItems,
            (int)$rawIdx,
            (string)$rawRepeat,
            $rawShuffled
        );
        if (!empty($data['original_order'])) {
            $manager->originalOrder = (array)$data['original_order'];
        }
        return $manager;
    }
}
