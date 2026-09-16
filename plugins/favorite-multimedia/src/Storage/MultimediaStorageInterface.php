<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Storage;

interface MultimediaStorageInterface
{
    /**
     * Put an object into storage.
     *
     * @param string $key Relative object key (e.g. multimedia/movies/1/renditions/720p.mp4)
     * @param string|resource $content File contents or stream resource
     * @param array $options Additional options (mime_type, visibility, metadata)
     */
    public function put(string $key, mixed $content, array $options = []): bool;

    /**
     * Get object contents.
     */
    public function get(string $key): ?string;

    /**
     * Open readable stream resource for object.
     *
     * @return resource|null
     */
    public function readStream(string $key);

    /**
     * Check if object exists.
     */
    public function exists(string $key): bool;

    /**
     * Get size of object in bytes.
     */
    public function size(string $key): int;

    /**
     * Delete an object.
     */
    public function delete(string $key): bool;

    /**
     * Delete all objects under a prefix/directory.
     *
     * @return int Number of deleted objects
     */
    public function deleteDirectory(string $prefix): int;

    /**
     * Get public or base URL for object (for public content only).
     */
    public function url(string $key): string;

    /**
     * Generate short-lived presigned/signed URL for protected media.
     *
     * @param string $key Object key
     * @param int $ttlSeconds Expiration time in seconds (default 300 = 5 mins)
     * @param array $options Additional options (download_name, content_type)
     */
    public function temporaryUrl(string $key, int $ttlSeconds = 300, array $options = []): ?string;

    /**
     * Return canonical driver name ('local', 's3', etc.).
     */
    public function getDriverName(): string;

    /**
     * Perform connectivity/health check.
     *
     * @return array ['success' => bool, 'message' => string, 'details' => array]
     */
    public function testConnection(): array;
}
