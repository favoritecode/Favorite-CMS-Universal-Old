<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Theme\Homepage;

/**
 * Immutable and validated homepage section configuration model.
 */
final class HomepageConfig
{
    public const SCHEMA_VERSION = '1.0.0';
    public const MAX_SECTIONS = 20;

    private array $sections;
    private string $schemaVersion;

    public function __construct(?array $sections = null, string $schemaVersion = self::SCHEMA_VERSION)
    {
        $this->schemaVersion = $schemaVersion;
        if ($sections === null) {
            $this->sections = self::getDefaultSections();
        } else {
            $this->sections = array_values($sections);
        }
    }

    public static function getDefaultSections(): array
    {
        return [
            [
                'id' => 'sec_hero',
                'type' => SectionRegistry::TYPE_HERO,
                'enabled' => true,
                'title' => 'Featured Premiere',
                'subtitle' => 'Watch the biggest hits this season',
                'content_source' => 'featured',
                'limit' => 5,
                'sort' => 'latest',
                'card_style' => 'hero',
                'layout' => 'hero_slider',
                'device_visibility' => 'all',
                'show_view_all' => false,
                'show_metadata' => true,
                'show_badges' => true,
                'show_progress' => false,
                'position' => 1,
                'options' => [
                    'slide_duration' => 6,
                    'auto_rotation' => true,
                ],
            ],
            [
                'id' => 'sec_continue_watching',
                'type' => SectionRegistry::TYPE_CONTINUE_WATCHING,
                'enabled' => true,
                'title' => 'Continue Watching',
                'subtitle' => 'Pick up right where you left off',
                'content_source' => 'personal_progress',
                'limit' => 8,
                'sort' => 'recent',
                'card_style' => 'landscape',
                'layout' => 'rail',
                'device_visibility' => 'all',
                'show_view_all' => true,
                'show_metadata' => true,
                'show_badges' => true,
                'show_progress' => true,
                'position' => 2,
                'options' => [],
            ],
            [
                'id' => 'sec_continue_listening',
                'type' => SectionRegistry::TYPE_CONTINUE_LISTENING,
                'enabled' => true,
                'title' => 'Continue Listening',
                'subtitle' => 'Jump back into your music',
                'content_source' => 'personal_progress',
                'limit' => 6,
                'sort' => 'recent',
                'card_style' => 'song_row',
                'layout' => 'list',
                'device_visibility' => 'all',
                'show_view_all' => true,
                'show_metadata' => true,
                'show_badges' => false,
                'show_progress' => true,
                'position' => 3,
                'options' => [],
            ],
            [
                'id' => 'sec_trending',
                'type' => SectionRegistry::TYPE_TRENDING,
                'enabled' => true,
                'title' => 'Trending Now',
                'subtitle' => 'What everyone is streaming this week',
                'content_source' => 'trending',
                'limit' => 10,
                'sort' => 'trending',
                'card_style' => 'poster',
                'layout' => 'rail',
                'device_visibility' => 'all',
                'show_view_all' => true,
                'show_metadata' => true,
                'show_badges' => true,
                'show_progress' => false,
                'position' => 4,
                'options' => [],
            ],
            [
                'id' => 'sec_latest_movies',
                'type' => SectionRegistry::TYPE_LATEST_MOVIES,
                'enabled' => true,
                'title' => 'Latest Movies',
                'subtitle' => 'Fresh releases added to the catalog',
                'content_source' => 'movies',
                'limit' => 10,
                'sort' => 'latest',
                'card_style' => 'poster',
                'layout' => 'rail',
                'device_visibility' => 'all',
                'show_view_all' => true,
                'show_metadata' => true,
                'show_badges' => true,
                'show_progress' => false,
                'position' => 5,
                'options' => [],
            ],
            [
                'id' => 'sec_popular_series',
                'type' => SectionRegistry::TYPE_POPULAR_SERIES,
                'enabled' => true,
                'title' => 'Popular Web Series',
                'subtitle' => 'Series captivating viewers right now',
                'content_source' => 'series',
                'limit' => 10,
                'sort' => 'popular',
                'card_style' => 'poster',
                'layout' => 'rail',
                'device_visibility' => 'all',
                'show_view_all' => true,
                'show_metadata' => true,
                'show_badges' => true,
                'show_progress' => false,
                'position' => 6,
                'options' => [],
            ],
            [
                'id' => 'sec_music_spotlight',
                'type' => SectionRegistry::TYPE_MUSIC_SPOTLIGHT,
                'enabled' => true,
                'title' => 'Music Spotlight',
                'subtitle' => 'Featured soundscapes & chart toppers',
                'content_source' => 'music',
                'limit' => 8,
                'sort' => 'popular',
                'card_style' => 'album',
                'layout' => 'rail',
                'device_visibility' => 'all',
                'show_view_all' => true,
                'show_metadata' => true,
                'show_badges' => true,
                'show_progress' => false,
                'position' => 7,
                'options' => [],
            ],
            [
                'id' => 'sec_new_songs',
                'type' => SectionRegistry::TYPE_NEW_SONGS,
                'enabled' => true,
                'title' => 'New Songs',
                'subtitle' => 'Fresh beats and singles',
                'content_source' => 'songs',
                'limit' => 8,
                'sort' => 'latest',
                'card_style' => 'song_row',
                'layout' => 'list',
                'device_visibility' => 'all',
                'show_view_all' => true,
                'show_metadata' => true,
                'show_badges' => false,
                'show_progress' => false,
                'position' => 8,
                'options' => [],
            ],
            [
                'id' => 'sec_featured_albums',
                'type' => SectionRegistry::TYPE_FEATURED_ALBUMS,
                'enabled' => true,
                'title' => 'Featured Albums',
                'subtitle' => 'Timeless records curated by our editors',
                'content_source' => 'albums',
                'limit' => 8,
                'sort' => 'latest',
                'card_style' => 'album',
                'layout' => 'rail',
                'device_visibility' => 'all',
                'show_view_all' => true,
                'show_metadata' => true,
                'show_badges' => false,
                'show_progress' => false,
                'position' => 9,
                'options' => [],
            ],
            [
                'id' => 'sec_audio_playlists',
                'type' => SectionRegistry::TYPE_AUDIO_PLAYLISTS,
                'enabled' => true,
                'title' => 'Vibe Playlists',
                'subtitle' => 'Soundtracks for every mood and occasion',
                'content_source' => 'audio_playlists',
                'limit' => 6,
                'sort' => 'latest',
                'card_style' => 'playlist',
                'layout' => 'rail',
                'device_visibility' => 'all',
                'show_view_all' => true,
                'show_metadata' => true,
                'show_badges' => false,
                'show_progress' => false,
                'position' => 10,
                'options' => [],
            ],
            [
                'id' => 'sec_genres',
                'type' => SectionRegistry::TYPE_GENRES,
                'enabled' => true,
                'title' => 'Explore by Genre',
                'subtitle' => 'Find exactly what fits your mood',
                'content_source' => 'genres',
                'limit' => 8,
                'sort' => 'popular',
                'card_style' => 'landscape',
                'layout' => 'rail',
                'device_visibility' => 'all',
                'show_view_all' => true,
                'show_metadata' => false,
                'show_badges' => false,
                'show_progress' => false,
                'position' => 11,
                'options' => [],
            ],
        ];
    }

    public static function fromArray(array $data): self
    {
        $schema = (string)($data['schema_version'] ?? $data['homepage_schema_version'] ?? self::SCHEMA_VERSION);
        $sections = isset($data['sections']) && is_array($data['sections']) ? $data['sections'] : null;
        return new self($sections, $schema);
    }

    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'homepage_schema_version' => $this->schemaVersion,
            'sections' => $this->sections,
        ];
    }

    public function getSchemaVersion(): string
    {
        return $this->schemaVersion;
    }

    public function getSections(): array
    {
        return $this->sections;
    }

    public function getSection(string $id): ?array
    {
        foreach ($this->sections as $sec) {
            if ($sec['id'] === $id) {
                return $sec;
            }
        }
        return null;
    }

    public function withSection(string $id, array $data): self
    {
        $cloned = $this->sections;
        foreach ($cloned as $idx => $sec) {
            if ($sec['id'] === $id) {
                $cloned[$idx] = array_merge($sec, $data);
                break;
            }
        }
        return new self($cloned, $this->schemaVersion);
    }

    public function addSection(array $sectionData): self
    {
        $cloned = $this->sections;
        if (empty($sectionData['id'])) {
            $sectionData['id'] = 'sec_' . bin2hex(random_bytes(4));
        }
        $sectionData['position'] = count($cloned) + 1;
        $cloned[] = $sectionData;
        return new self($cloned, $this->schemaVersion);
    }

    public function removeSection(string $id): self
    {
        $filtered = array_values(array_filter($this->sections, fn($s) => $s['id'] !== $id));
        foreach ($filtered as $i => &$item) {
            $item['position'] = $i + 1;
        }
        return new self($filtered, $this->schemaVersion);
    }

    public function duplicateSection(string $id): self
    {
        $cloned = $this->sections;
        $toDup = null;
        $pos = -1;
        foreach ($cloned as $i => $sec) {
            if ($sec['id'] === $id) {
                $toDup = $sec;
                $pos = $i;
                break;
            }
        }

        if ($toDup !== null) {
            $dup = $toDup;
            $dup['id'] = 'sec_' . bin2hex(random_bytes(4));
            $dup['title'] = $toDup['title'] . ' (Copy)';
            array_splice($cloned, $pos + 1, 0, [$dup]);

            foreach ($cloned as $i => &$item) {
                $item['position'] = $i + 1;
            }
        }

        return new self($cloned, $this->schemaVersion);
    }

    public function reorder(array $orderedIds): self
    {
        $map = [];
        foreach ($this->sections as $sec) {
            $map[$sec['id']] = $sec;
        }

        $reordered = [];
        $pos = 1;
        foreach ($orderedIds as $id) {
            if (isset($map[$id])) {
                $item = $map[$id];
                $item['position'] = $pos++;
                $reordered[] = $item;
                unset($map[$id]);
            }
        }

        // Append any unmentioned sections
        foreach ($map as $remaining) {
            $remaining['position'] = $pos++;
            $reordered[] = $remaining;
        }

        return new self($reordered, $this->schemaVersion);
    }

    public function sanitize(): self
    {
        $sanitized = [];
        foreach ($this->sections as $sec) {
            $sec['id'] = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($sec['id'] ?? 'sec_' . bin2hex(random_bytes(4))));
            $sec['type'] = SectionRegistry::has((string)$sec['type']) ? (string)$sec['type'] : SectionRegistry::TYPE_LATEST_MOVIES;
            $sec['enabled'] = (bool)($sec['enabled'] ?? true);
            $sec['title'] = strip_tags((string)($sec['title'] ?? 'Section'));
            $sec['subtitle'] = strip_tags((string)($sec['subtitle'] ?? ''));
            $sec['limit'] = max(1, min(50, (int)($sec['limit'] ?? 10)));
            $sec['sort'] = in_array((string)($sec['sort'] ?? ''), ['latest', 'popular', 'trending', 'rating', 'recent', 'manual', 'random'], true)
                ? (string)$sec['sort'] : 'latest';
            $sec['card_style'] = in_array((string)($sec['card_style'] ?? ''), ['poster', 'landscape', 'album', 'artist', 'playlist', 'song_row', 'hero', 'mixed'], true)
                ? (string)$sec['card_style'] : 'poster';
            $sec['layout'] = in_array((string)($sec['layout'] ?? ''), ['rail', 'grid', 'list', 'hero_slider'], true)
                ? (string)$sec['layout'] : 'rail';
            $sec['device_visibility'] = in_array((string)($sec['device_visibility'] ?? ''), ['all', 'desktop', 'tablet', 'mobile'], true)
                ? (string)$sec['device_visibility'] : 'all';
            $sec['show_view_all'] = (bool)($sec['show_view_all'] ?? true);
            $sec['show_metadata'] = (bool)($sec['show_metadata'] ?? true);
            $sec['show_badges'] = (bool)($sec['show_badges'] ?? true);
            $sec['show_progress'] = (bool)($sec['show_progress'] ?? false);
            $sec['options'] = is_array($sec['options'] ?? null) ? $sec['options'] : [];

            if (isset($sec['options']['manual_ids']) && is_array($sec['options']['manual_ids'])) {
                $sec['options']['manual_ids'] = array_values(array_unique(array_filter(array_map('intval', $sec['options']['manual_ids']), fn($i) => $i > 0)));
            }

            $sanitized[] = $sec;
        }

        return new self($sanitized, $this->schemaVersion);
    }

    public function validate(): array
    {
        $errors = [];

        if (empty($this->sections)) {
            $errors[] = 'At least one homepage section is required.';
            return $errors;
        }

        if (count($this->sections) > self::MAX_SECTIONS) {
            $errors[] = 'Number of sections (' . count($this->sections) . ') exceeds maximum allowed limit of ' . self::MAX_SECTIONS . '.';
            return $errors;
        }

        $ids = [];

        foreach ($this->sections as $index => $sec) {
            $id = (string)($sec['id'] ?? '');
            if ($id === '') {
                $errors[] = "Section at index {$index} must have a non-empty ID.";
            } elseif (in_array($id, $ids, true)) {
                $errors[] = "Duplicate section ID '{$id}' detected.";
            } else {
                $ids[] = $id;
            }

            $type = (string)($sec['type'] ?? '');
            if (!SectionRegistry::has($type)) {
                $errors[] = "Unknown section type '{$type}' in section '{$id}'.";
            }

            if (isset($sec['layout'])) {
                $layout = (string)$sec['layout'];
                if (!in_array($layout, ['rail', 'grid', 'list', 'hero_slider'], true)) {
                    $errors[] = "Section '{$id}' has unsupported layout '{$layout}'.";
                }
            }

            if (isset($sec['limit'])) {
                $limit = (int)$sec['limit'];
                if ($limit < 1 || $limit > 24) {
                    $errors[] = "Section '{$id}' limit must be between 1 and 24 (given: {$limit}).";
                }
            }
        }

        return $errors;
    }
}

