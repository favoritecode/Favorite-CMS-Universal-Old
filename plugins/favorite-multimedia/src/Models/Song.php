<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Models;

use FavoriteCMS\Models\BaseModel;

class Song extends BaseModel
{
    protected static string $table = 'multimedia_songs';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_UNPUBLISHED = 'unpublished';

    public const PLAYBACK_AUDIO = 'audio';
    public const PLAYBACK_VIDEO = 'video';
    public const PLAYBACK_AUDIO_VIDEO = 'audio_video';

    public function isPublished(): bool
    {
        return ($this->status ?? '') === self::STATUS_PUBLISHED;
    }

    public function isScheduled(): bool
    {
        return ($this->status ?? '') === self::STATUS_SCHEDULED;
    }

    public function isDraft(): bool
    {
        return ($this->status ?? '') === self::STATUS_DRAFT;
    }

    public function isUnpublished(): bool
    {
        return ($this->status ?? '') === self::STATUS_UNPUBLISHED;
    }

    public static function findBySlug(string $slug): ?self
    {
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $row = $db->selectOne("SELECT * FROM multimedia_songs WHERE slug = ?", [$slug]);
        return $row ? new static((array)$row) : null;
    }

    public static function published(int $limit = 20, int $offset = 0, ?int $artistId = null, ?int $albumId = null, ?string $genreSlug = null, ?string $search = null): array
    {
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $where = "s.status = 'published'";
        $params = [];

        if ($search !== null && trim($search) !== '') {
            $where .= " AND (s.title LIKE ? OR s.lyrics LIKE ?)";
            $term = '%' . trim($search) . '%';
            $params[] = $term;
            $params[] = $term;
        }

        if ($artistId !== null && $artistId > 0) {
            $where .= " AND s.artist_id = ?";
            $params[] = $artistId;
        }

        if ($albumId !== null && $albumId > 0) {
            $where .= " AND s.album_id = ?";
            $params[] = $albumId;
        }

        if ($genreSlug !== null && trim($genreSlug) !== '') {
            $sql = "SELECT s.* FROM multimedia_songs s
                    JOIN multimedia_content_genres cg ON s.id = cg.content_id AND cg.content_type = 'song'
                    JOIN multimedia_genres g ON cg.genre_id = g.id
                    WHERE {$where} AND g.slug = ?
                    ORDER BY s.featured DESC, s.release_date DESC, s.id DESC
                    LIMIT {$limit} OFFSET {$offset}";
            $params[] = $genreSlug;
        } else {
            $sql = "SELECT s.* FROM multimedia_songs s
                    WHERE {$where}
                    ORDER BY s.featured DESC, s.release_date DESC, s.id DESC
                    LIMIT {$limit} OFFSET {$offset}";
        }

        $rows = $db->select($sql, $params);
        return array_map(fn($r) => new static((array)$r), $rows);
    }

    public static function countPublished(?int $artistId = null, ?int $albumId = null, ?string $genreSlug = null, ?string $search = null): int
    {
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $where = "s.status = 'published'";
        $params = [];

        if ($search !== null && trim($search) !== '') {
            $where .= " AND (s.title LIKE ? OR s.lyrics LIKE ?)";
            $term = '%' . trim($search) . '%';
            $params[] = $term;
            $params[] = $term;
        }

        if ($artistId !== null && $artistId > 0) {
            $where .= " AND s.artist_id = ?";
            $params[] = $artistId;
        }

        if ($albumId !== null && $albumId > 0) {
            $where .= " AND s.album_id = ?";
            $params[] = $albumId;
        }

        if ($genreSlug !== null && trim($genreSlug) !== '') {
            $sql = "SELECT COUNT(DISTINCT s.id) as total FROM multimedia_songs s
                    JOIN multimedia_content_genres cg ON s.id = cg.content_id AND cg.content_type = 'song'
                    JOIN multimedia_genres g ON cg.genre_id = g.id
                    WHERE {$where} AND g.slug = ?";
            $params[] = $genreSlug;
        } else {
            $sql = "SELECT COUNT(s.id) as total FROM multimedia_songs s WHERE {$where}";
        }

        $row = $db->selectOne($sql, $params);
        return (int)($row->total ?? 0);
    }

    public function getArtist(): ?Artist
    {
        if (empty($this->artist_id)) {
            return null;
        }
        return Artist::find((int)$this->artist_id);
    }

    public function getAlbum(): ?Album
    {
        if (empty($this->album_id)) {
            return null;
        }
        return Album::find((int)$this->album_id);
    }

    public function getPlaybackType(): string
    {
        return strtolower(trim((string)($this->playback_type ?? self::PLAYBACK_AUDIO)));
    }

    public function getDefaultPlaybackMode(): string
    {
        return strtolower(trim((string)($this->default_playback_mode ?? self::PLAYBACK_AUDIO)));
    }

    public function hasAudio(): bool
    {
        $type = $this->getPlaybackType();
        if ($type === self::PLAYBACK_VIDEO) {
            return false;
        }
        return count($this->getAudioSources(true)) > 0;
    }

    public function hasVideo(): bool
    {
        $type = $this->getPlaybackType();
        if ($type === self::PLAYBACK_AUDIO) {
            return false;
        }
        return count($this->getVideoSources(true)) > 0;
    }

    public function getSources(bool $activeOnly = true, ?string $mediaKind = null): array
    {
        return MediaSource::getForContent('song', (int)$this->id, $activeOnly, $mediaKind);
    }

    public function getAudioSources(bool $activeOnly = true): array
    {
        return MediaSource::getForContent('song', (int)$this->id, $activeOnly, 'audio');
    }

    public function getVideoSources(bool $activeOnly = true): array
    {
        return MediaSource::getForContent('song', (int)$this->id, $activeOnly, 'video');
    }

    public function getDefaultAudioSource(): ?MediaSource
    {
        return MediaSource::getDefault('song', (int)$this->id, true, 'audio');
    }

    public function getDefaultVideoSource(): ?MediaSource
    {
        return MediaSource::getDefault('song', (int)$this->id, true, 'video');
    }

    public function getDefaultSource(?string $mediaKind = null): ?MediaSource
    {
        if ($mediaKind !== null) {
            return MediaSource::getDefault('song', (int)$this->id, true, $mediaKind);
        }
        $pref = $this->getDefaultPlaybackMode();
        if ($pref === self::PLAYBACK_VIDEO) {
            return $this->getDefaultVideoSource() ?: $this->getDefaultAudioSource();
        }
        return $this->getDefaultAudioSource() ?: $this->getDefaultVideoSource();
    }

    public function getGenres(): array
    {
        return Genre::getForContent('song', (int)$this->id);
    }

    public function incrementPlays(): void
    {
        $this->db->query("UPDATE multimedia_songs SET plays_count = plays_count + 1 WHERE id = ?", [(int)$this->id]);
    }

    public function getDurationFormatted(): string
    {
        $total = (int)($this->duration ?? 0);
        if ($total <= 0) {
            return '';
        }
        $minutes = floor($total / 60);
        $seconds = $total % 60;
        return sprintf('%02d:%02d', $minutes, $seconds);
    }

    public function getDownloadUrl(): ?string
    {
        $url = trim((string)($this->download_url ?? ''));
        return ($url !== '') ? $url : null;
    }

    public static function forUser(int $userId): array
    {
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $rows = $db->select("SELECT * FROM multimedia_songs WHERE user_id = ? ORDER BY id DESC", [$userId]);
        return array_map(fn($r) => new static((array)$r), $rows);
    }
}

