<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Services;

use FavoriteCMS\Models\Setting;

class MediaDeliveryTokenService
{
    private static ?string $cachedSecret = null;

    public static function setSecretForTesting(?string $secret): void
    {
        self::$cachedSecret = $secret;
    }

    private static function getSecret(): string
    {
        if (self::$cachedSecret !== null) {
            return self::$cachedSecret;
        }

        $secret = (string)Setting::get('multimedia_token_secret', '');
        if ($secret === '') {
            $secret = hash('sha256', (string)getenv('APP_KEY') . '_multimedia_delivery_salt_8716');
        }

        return $secret;
    }

    /**
     * Generate secure delivery signature and expiration for an object key.
     */
    public static function generateToken(string $key, int $ttlSeconds = 300, array $context = []): array
    {
        $ttl = max(10, min(86400, $ttlSeconds));
        $exp = time() + $ttl;
        $normKey = trim(str_replace('\\', '/', $key), '/');

        $contentType = (string)($context['content_type'] ?? '');
        $contentId   = (int)($context['content_id'] ?? 0);
        $sourceId    = (int)($context['source_id'] ?? 0);

        $payload = "{$normKey}|{$exp}|{$contentType}|{$contentId}|{$sourceId}";
        $sig = hash_hmac('sha256', $payload, self::getSecret());

        return [
            'key'          => $normKey,
            'exp'          => $exp,
            'sig'          => $sig,
            'content_type' => $contentType,
            'content_id'   => $contentId,
            'source_id'    => $sourceId,
        ];
    }

    /**
     * Validate delivery signature against key and context.
     */
    public static function validateToken(string $sig, string $key, int $exp, array $context = []): bool
    {
        if ($sig === '' || $exp <= 0) {
            return false;
        }

        // 1. Expiration check
        if (time() > $exp) {
            return false;
        }

        // 2. Bound key and context
        $normKey     = trim(str_replace('\\', '/', $key), '/');
        $contentType = (string)($context['content_type'] ?? '');
        $contentId   = (int)($context['content_id'] ?? 0);
        $sourceId    = (int)($context['source_id'] ?? 0);

        $expectedPayload = "{$normKey}|{$exp}|{$contentType}|{$contentId}|{$sourceId}";
        $expectedSig = hash_hmac('sha256', $expectedPayload, self::getSecret());

        return hash_equals($expectedSig, $sig);
    }

    /**
     * Generate token scoped specifically to an HLS media source stream.
     */
    public static function generateHlsStreamToken(int $sourceId, int $ttlSeconds = 300): array
    {
        return self::generateToken("hls_source_{$sourceId}", $ttlSeconds, ['source_id' => $sourceId]);
    }

    /**
     * Validate an HLS media source stream token.
     */
    public static function validateHlsStreamToken(string $sig, int $sourceId, int $exp): bool
    {
        return self::validateToken($sig, "hls_source_{$sourceId}", $exp, ['source_id' => $sourceId]);
    }
}
