<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Models;

use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Models\BaseModel;

class MediaProcessingJob extends BaseModel
{
    protected static string $table = 'multimedia_processing_jobs';

    public const STATUS_PENDING    = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED  = 'completed';
    public const STATUS_FAILED     = 'failed';
    public const STATUS_CANCELLED  = 'cancelled';

    public const TYPE_TRANSCODE_MP4      = 'transcode_mp4';
    public const TYPE_TRANSCODE_HLS      = 'transcode_hls';
    public const TYPE_GENERATE_THUMBNAIL = 'generate_thumbnail';
    public const TYPE_EXTRACT_AUDIO      = 'extract_audio';
    public const TYPE_FULL_PIPELINE      = 'full_pipeline';

    public const ALLOWED_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PROCESSING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    public const ALLOWED_TYPES = [
        self::TYPE_TRANSCODE_MP4,
        self::TYPE_TRANSCODE_HLS,
        self::TYPE_GENERATE_THUMBNAIL,
        self::TYPE_EXTRACT_AUDIO,
        self::TYPE_FULL_PIPELINE,
    ];

    private static function getDb(): Database
    {
        return Container::getInstance()->get(Database::class);
    }

    public static function find(int $id): ?static
    {
        if ($id <= 0) {
            return null;
        }

        $row = self::getDb()->selectOne("SELECT * FROM multimedia_processing_jobs WHERE id = ? LIMIT 1", [$id]);
        return $row ? new static((array)$row) : null;
    }

    public function getSettings(): array
    {
        $raw = $this->settings ?? '';
        if (empty($raw)) {
            return [];
        }
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string)$raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function isPending(): bool
    {
        return ($this->status ?? '') === self::STATUS_PENDING;
    }

    public function isProcessing(): bool
    {
        return ($this->status ?? '') === self::STATUS_PROCESSING;
    }

    public function isCompleted(): bool
    {
        return ($this->status ?? '') === self::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return ($this->status ?? '') === self::STATUS_FAILED;
    }

    public function isCancelled(): bool
    {
        return ($this->status ?? '') === self::STATUS_CANCELLED;
    }

    public function canRetry(): bool
    {
        return in_array($this->status ?? '', [self::STATUS_FAILED, self::STATUS_CANCELLED], true);
    }

    public function canCancel(): bool
    {
        return in_array($this->status ?? '', [self::STATUS_PENDING, self::STATUS_PROCESSING], true);
    }

    public function getContent(): ?object
    {
        $type = (string)($this->content_type ?? '');
        $id = (int)($this->content_id ?? 0);
        if ($id <= 0) {
            return null;
        }

        return match ($type) {
            'movie'   => Movie::find($id),
            'episode' => Episode::find($id),
            'song'    => Song::find($id),
            default   => null,
        };
    }

    public function getContentTitle(): string
    {
        $content = $this->getContent();
        return $content ? (string)($content->title ?? 'Untitled') : ucfirst((string)$this->content_type) . ' #' . $this->content_id;
    }

    public function getSource(): ?MediaSource
    {
        $sourceId = (int)($this->source_id ?? 0);
        return $sourceId > 0 ? MediaSource::find($sourceId) : null;
    }

    public static function findPending(int $limit = 10): array
    {
        $limit = max(1, min(100, $limit));
        $rows = self::getDb()->select(
            "SELECT * FROM multimedia_processing_jobs 
             WHERE status = ? 
             ORDER BY created_at ASC, id ASC 
             LIMIT ?",
            [self::STATUS_PENDING, $limit]
        );
        return array_map(fn($r) => new static((array)$r), $rows);
    }

    public static function getForContent(string $contentType, int $contentId): array
    {
        $rows = self::getDb()->select(
            "SELECT * FROM multimedia_processing_jobs 
             WHERE content_type = ? AND content_id = ? 
             ORDER BY id DESC",
            [$contentType, $contentId]
        );
        return array_map(fn($r) => new static((array)$r), $rows);
    }

    public static function getRecent(int $limit = 50, ?string $status = null): array
    {
        $limit = max(1, min(200, $limit));
        $where = "";
        $params = [];

        if ($status && in_array($status, self::ALLOWED_STATUSES, true)) {
            $where = "WHERE status = ?";
            $params[] = $status;
        }

        $params[] = $limit;
        $rows = self::getDb()->select(
            "SELECT * FROM multimedia_processing_jobs 
             {$where} 
             ORDER BY id DESC 
             LIMIT ?",
            $params
        );
        return array_map(fn($r) => new static((array)$r), $rows);
    }

    public static function countByStatus(): array
    {
        $counts = [
            self::STATUS_PENDING    => 0,
            self::STATUS_PROCESSING => 0,
            self::STATUS_COMPLETED  => 0,
            self::STATUS_FAILED     => 0,
            self::STATUS_CANCELLED  => 0,
            'total'                 => 0,
        ];

        try {
            $rows = self::getDb()->select(
                "SELECT status, COUNT(*) as cnt FROM multimedia_processing_jobs GROUP BY status"
            );
            foreach ($rows as $r) {
                $st = (string)$r->status;
                $c = (int)$r->cnt;
                if (isset($counts[$st])) {
                    $counts[$st] = $c;
                }
                $counts['total'] += $c;
            }
        } catch (\Throwable) {
            // Table might not exist yet
        }

        return $counts;
    }
}
