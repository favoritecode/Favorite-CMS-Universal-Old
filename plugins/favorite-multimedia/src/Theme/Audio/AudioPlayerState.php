<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Theme\Audio;

/**
 * Value object representing serialized audio player state.
 * Stored safely in sessionStorage; completely stripped of raw stream URLs or protected tokens.
 */
final class AudioPlayerState
{
    public const STATUS_IDLE = 'idle';
    public const STATUS_PLAYING = 'playing';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_BUFFERING = 'buffering';
    public const STATUS_ENDED = 'ended';
    public const STATUS_ERROR = 'error';

    public const REPEAT_OFF = 'off';
    public const REPEAT_QUEUE = 'queue';
    public const REPEAT_ONE = 'one';

    public const CONTEXT_MANUAL = 'manual';
    public const CONTEXT_ALBUM = 'album';
    public const CONTEXT_PLAYLIST = 'playlist';
    public const CONTEXT_ARTIST = 'artist';
    public const CONTEXT_DISCOVERY = 'discovery';

    public ?int $songId = null;
    public string $contentType = 'song';
    public string $status = self::STATUS_IDLE;
    public float $position = 0.0;
    public float $duration = 0.0;
    public string $repeatMode = self::REPEAT_OFF;
    public bool $isShuffled = false;
    public float $volume = 1.0;
    public bool $isMuted = false;
    public string $queueContext = self::CONTEXT_MANUAL;
    public ?int $contextId = null;
    public int $schemaVersion = 1;
    public bool $isPlayingIntent = false;
    public array $queue = [];

    /**
     * @param int|array<string, mixed>|null $songIdOrData
     */
    public function __construct(
        int|array|null $songIdOrData = null,
        string $contentType = 'song',
        string $status = self::STATUS_IDLE,
        float $position = 0.0,
        float $duration = 0.0,
        string $repeatMode = self::REPEAT_OFF,
        bool $isShuffled = false,
        float $volume = 1.0,
        bool $isMuted = false,
        string $queueContext = self::CONTEXT_MANUAL,
        ?int $contextId = null,
        int $schemaVersion = 1
    ) {
        if (is_array($songIdOrData)) {
            $this->initFromArray($songIdOrData);
            return;
        }

        $this->songId = $songIdOrData;
        $this->contentType = $contentType;
        $this->status = in_array($status, [self::STATUS_IDLE, self::STATUS_PLAYING, self::STATUS_PAUSED, self::STATUS_BUFFERING, self::STATUS_ENDED, self::STATUS_ERROR], true)
            ? $status : self::STATUS_IDLE;
        $this->position = max(0.0, $position);
        $this->duration = max(0.0, $duration);
        $this->repeatMode = in_array($repeatMode, [self::REPEAT_OFF, self::REPEAT_QUEUE, self::REPEAT_ONE], true)
            ? $repeatMode : self::REPEAT_OFF;
        $this->isShuffled = $isShuffled;
        $this->volume = max(0.0, min(1.0, $volume));
        $this->isMuted = $isMuted;
        $this->queueContext = in_array($queueContext, [self::CONTEXT_MANUAL, self::CONTEXT_ALBUM, self::CONTEXT_PLAYLIST, self::CONTEXT_ARTIST, self::CONTEXT_DISCOVERY], true)
            ? $queueContext : self::CONTEXT_MANUAL;
        $this->contextId = $contextId;
        $this->schemaVersion = $schemaVersion;
        $this->isPlayingIntent = ($this->status === self::STATUS_PLAYING);
    }

    private function initFromArray(array $data): void
    {
        $rawSongId = $data['songId'] ?? $data['song_id'] ?? null;
        $this->songId = $rawSongId !== null ? (int)$rawSongId : null;
        $this->contentType = (string)($data['content_type'] ?? $data['contentType'] ?? 'song');
        $rawStatus = (string)($data['status'] ?? self::STATUS_IDLE);
        $this->status = in_array($rawStatus, [self::STATUS_IDLE, self::STATUS_PLAYING, self::STATUS_PAUSED, self::STATUS_BUFFERING, self::STATUS_ENDED, self::STATUS_ERROR], true)
            ? $rawStatus : self::STATUS_IDLE;
        $this->position = max(0.0, (float)($data['position'] ?? 0.0));
        $this->duration = max(0.0, (float)($data['duration'] ?? 0.0));
        $rawRepeat = (string)($data['repeatMode'] ?? $data['repeat_mode'] ?? self::REPEAT_OFF);
        $this->repeatMode = in_array($rawRepeat, [self::REPEAT_OFF, self::REPEAT_QUEUE, self::REPEAT_ONE], true)
            ? $rawRepeat : self::REPEAT_OFF;
        $this->isShuffled = !empty($data['isShuffled']) || !empty($data['is_shuffled']);
        $this->volume = max(0.0, min(1.0, (float)($data['volume'] ?? 1.0)));
        $this->isMuted = !empty($data['isMuted']) || !empty($data['is_muted']);
        $rawContext = (string)($data['queueContext'] ?? $data['queue_context'] ?? self::CONTEXT_MANUAL);
        $this->queueContext = in_array($rawContext, [self::CONTEXT_MANUAL, self::CONTEXT_ALBUM, self::CONTEXT_PLAYLIST, self::CONTEXT_ARTIST, self::CONTEXT_DISCOVERY], true)
            ? $rawContext : self::CONTEXT_MANUAL;
        $rawContextId = $data['contextId'] ?? $data['context_id'] ?? null;
        $this->contextId = $rawContextId !== null ? (int)$rawContextId : null;
        $this->schemaVersion = (int)($data['schemaVersion'] ?? $data['schema_version'] ?? 1);

        if (isset($data['isPlayingIntent'])) {
            $this->isPlayingIntent = (bool)$data['isPlayingIntent'];
        } else {
            $this->isPlayingIntent = ($this->status === self::STATUS_PLAYING);
        }

        // Sanitize queue items to guarantee zero stream URLs or protected tokens
        $rawQueue = $data['queue'] ?? $data['items'] ?? [];
        $sanitizedQueue = [];
        if (is_array($rawQueue)) {
            foreach ($rawQueue as $item) {
                if (!is_array($item)) continue;
                $sanitizedQueue[] = [
                    'id'       => (int)($item['id'] ?? 0),
                    'title'    => (string)($item['title'] ?? 'Untitled'),
                    'artist'   => (string)($item['artist'] ?? $item['artist_name'] ?? ''),
                    'cover'    => (string)($item['cover'] ?? ''),
                    'duration' => (int)($item['duration'] ?? 0),
                ];
            }
        }
        $this->queue = $sanitizedQueue;
    }

    public function getSongId(): ?int
    {
        return $this->songId;
    }

    public function getContentType(): string
    {
        return $this->contentType;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isPlaying(): bool
    {
        return $this->status === self::STATUS_PLAYING;
    }

    public function getPosition(): float
    {
        return $this->position;
    }

    public function getDuration(): float
    {
        return $this->duration;
    }

    public function getRepeatMode(): string
    {
        return $this->repeatMode;
    }

    public function isShuffled(): bool
    {
        return $this->isShuffled;
    }

    public function getVolume(): float
    {
        return $this->volume;
    }

    public function isMuted(): bool
    {
        return $this->isMuted;
    }

    public function getQueueContext(): string
    {
        return $this->queueContext;
    }

    public function getContextId(): ?int
    {
        return $this->contextId;
    }

    public function getSchemaVersion(): int
    {
        return $this->schemaVersion;
    }

    public function withSong(?int $songId, float $duration = 0.0): self
    {
        $clone = clone $this;
        $clone->songId = $songId;
        $clone->duration = max(0.0, $duration);
        $clone->position = 0.0;
        return $clone;
    }

    public function withPosition(float $pos): self
    {
        $clone = clone $this;
        $clone->position = max(0.0, $pos);
        return $clone;
    }

    public function withStatus(string $status): self
    {
        $clone = clone $this;
        if (in_array($status, [self::STATUS_IDLE, self::STATUS_PLAYING, self::STATUS_PAUSED, self::STATUS_BUFFERING, self::STATUS_ENDED, self::STATUS_ERROR], true)) {
            $clone->status = $status;
            $clone->isPlayingIntent = ($status === self::STATUS_PLAYING);
        }
        return $clone;
    }

    public function withVolume(float $volume, ?bool $isMuted = null): self
    {
        $clone = clone $this;
        $clone->volume = max(0.0, min(1.0, $volume));
        if ($isMuted !== null) {
            $clone->isMuted = $isMuted;
        }
        return $clone;
    }

    public function withRepeatMode(string $repeatMode): self
    {
        $clone = clone $this;
        if (in_array($repeatMode, [self::REPEAT_OFF, self::REPEAT_QUEUE, self::REPEAT_ONE], true)) {
            $clone->repeatMode = $repeatMode;
        }
        return $clone;
    }

    public function withShuffle(bool $shuffled): self
    {
        $clone = clone $this;
        $clone->isShuffled = $shuffled;
        return $clone;
    }

    public function withContext(string $context, ?int $contextId = null): self
    {
        $clone = clone $this;
        if (in_array($context, [self::CONTEXT_MANUAL, self::CONTEXT_ALBUM, self::CONTEXT_PLAYLIST, self::CONTEXT_ARTIST, self::CONTEXT_DISCOVERY], true)) {
            $clone->queueContext = $context;
        }
        $clone->contextId = $contextId;
        return $clone;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'songId'          => $this->songId,
            'song_id'         => $this->songId,
            'contentType'     => $this->contentType,
            'content_type'    => $this->contentType,
            'status'          => $this->status,
            'isPlayingIntent' => $this->isPlayingIntent,
            'position'        => $this->position,
            'duration'        => $this->duration,
            'repeatMode'      => $this->repeatMode,
            'repeat_mode'     => $this->repeatMode,
            'isShuffled'      => $this->isShuffled,
            'is_shuffled'     => $this->isShuffled,
            'volume'          => $this->volume,
            'isMuted'         => $this->isMuted,
            'is_muted'        => $this->isMuted,
            'queueContext'    => $this->queueContext,
            'queue_context'   => $this->queueContext,
            'contextId'       => $this->contextId,
            'context_id'      => $this->contextId,
            'schemaVersion'   => $this->schemaVersion,
            'schema_version'  => $this->schemaVersion,
            'queue'           => $this->queue,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);
        return new self(is_array($data) ? $data : []);
    }
}

