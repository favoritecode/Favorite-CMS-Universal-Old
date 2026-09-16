<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Storage;

use FavoriteCMS\Core\Logger;

class S3CompatibleMultimediaStorage implements MultimediaStorageInterface
{
    protected string $endpoint;
    protected string $region;
    protected string $bucket;
    protected string $accessKey;
    protected string $secretKey;
    protected string $pathPrefix;
    protected ?string $cdnBaseUrl;
    protected bool $usePathStyle;

    /**
     * @var array|null Test mock environment
     */
    protected static ?array $mockEnv = null;

    public function __construct(array $config = [])
    {
        $this->endpoint    = rtrim($config['endpoint'] ?? 'https://s3.amazonaws.com', '/');
        $this->region      = $config['region'] ?? 'us-east-1';
        $this->bucket      = trim($config['bucket'] ?? '');
        $this->accessKey   = trim($config['access_key'] ?? '');
        $this->secretKey   = trim($config['secret_key'] ?? '');
        $this->pathPrefix  = trim($config['path_prefix'] ?? 'multimedia', '/');
        $this->cdnBaseUrl  = !empty($config['cdn_base_url']) ? rtrim($config['cdn_base_url'], '/') : null;
        $this->usePathStyle = (bool)($config['use_path_style'] ?? true);
    }

    public static function setMockEnvironment(?array $mock): void
    {
        self::$mockEnv = $mock;
    }

    public static function resetMockEnvironment(): void
    {
        self::$mockEnv = null;
    }

    public function getDriverName(): string
    {
        return 's3';
    }

    public function getBucket(): string
    {
        return $this->bucket;
    }

    public function getPathPrefix(): string
    {
        return $this->pathPrefix;
    }

    public function getCdnBaseUrl(): ?string
    {
        return $this->cdnBaseUrl;
    }

    public function sanitizeKey(string $key): string
    {
        $norm = str_replace('\\', '/', $key);
        $norm = str_replace("\0", '', $norm);
        $norm = preg_replace('#/+#', '/', $norm) ?? '';
        $parts = explode('/', trim($norm, '/'));
        $safeParts = [];

        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                return '';
            }
            $safeParts[] = $part;
        }

        return implode('/', $safeParts);
    }

    public function buildFullKey(string $key): string
    {
        $sanitized = $this->sanitizeKey($key);
        if ($this->pathPrefix === '') {
            return $sanitized;
        }
        return $this->pathPrefix . '/' . $sanitized;
    }

    public function put(string $key, mixed $content, array $options = []): bool
    {
        $fullKey = $this->buildFullKey($key);
        if ($fullKey === '') {
            return false;
        }

        if (self::$mockEnv !== null) {
            if (isset(self::$mockEnv['put_callback'])) {
                $cb = self::$mockEnv['put_callback'];
                return (bool)$cb($key, $content, $options);
            }
            if (isset(self::$mockEnv['storage'])) {
                $data = is_resource($content) ? stream_get_contents($content) : (string)$content;
                self::$mockEnv['storage'][$fullKey] = [
                    'data'     => $data,
                    'size'     => strlen($data),
                    'mime'     => $options['mime_type'] ?? 'application/octet-stream',
                    'meta'     => $options,
                ];
                return true;
            }
            return self::$mockEnv['available'] ?? true;
        }

        $body = is_resource($content) ? (string)stream_get_contents($content) : (string)$content;
        $mime = $options['mime_type'] ?? $this->detectMimeType($key);

        $headers = [
            'Content-Type' => $mime,
            'x-amz-acl'    => 'private', // Protected default!
        ];

        $res = $this->sendRequest('PUT', $fullKey, $body, $headers);
        return ($res['status'] >= 200 && $res['status'] < 300);
    }

    public function get(string $key): ?string
    {
        $fullKey = $this->buildFullKey($key);
        if ($fullKey === '') {
            return null;
        }

        if (self::$mockEnv !== null) {
            if (isset(self::$mockEnv['storage'][$fullKey])) {
                return self::$mockEnv['storage'][$fullKey]['data'];
            }
            return self::$mockEnv['get_default'] ?? null;
        }

        $res = $this->sendRequest('GET', $fullKey);
        if ($res['status'] === 200) {
            return $res['body'];
        }

        return null;
    }

    public function readStream(string $key)
    {
        $data = $this->get($key);
        if ($data === null) {
            return null;
        }

        $stream = fopen('php://temp', 'r+');
        if ($stream) {
            fwrite($stream, $data);
            rewind($stream);
            return $stream;
        }

        return null;
    }

    public function exists(string $key): bool
    {
        $fullKey = $this->buildFullKey($key);
        if ($fullKey === '') {
            return false;
        }

        if (self::$mockEnv !== null) {
            if (isset(self::$mockEnv['storage'])) {
                return isset(self::$mockEnv['storage'][$fullKey]);
            }
            return (bool)(self::$mockEnv['available'] ?? false);
        }

        $res = $this->sendRequest('HEAD', $fullKey);
        return ($res['status'] === 200);
    }

    public function size(string $key): int
    {
        $fullKey = $this->buildFullKey($key);
        if ($fullKey === '') {
            return 0;
        }

        if (self::$mockEnv !== null) {
            if (isset(self::$mockEnv['storage'][$fullKey])) {
                return self::$mockEnv['storage'][$fullKey]['size'] ?? 0;
            }
            return 0;
        }

        $res = $this->sendRequest('HEAD', $fullKey);
        if ($res['status'] === 200 && isset($res['headers']['content-length'])) {
            return (int)$res['headers']['content-length'];
        }

        return 0;
    }

    public function delete(string $key): bool
    {
        $fullKey = $this->buildFullKey($key);
        if ($fullKey === '') {
            return false;
        }

        if (self::$mockEnv !== null) {
            if (isset(self::$mockEnv['storage'][$fullKey])) {
                unset(self::$mockEnv['storage'][$fullKey]);
            }
            return true;
        }

        $res = $this->sendRequest('DELETE', $fullKey);
        return ($res['status'] >= 200 && $res['status'] < 300);
    }

    public function deleteDirectory(string $prefix): int
    {
        $normPrefix = $this->sanitizeKey($prefix);
        if ($normPrefix === '' || $normPrefix === '.') {
            return 0; // Prevent wiping bucket root
        }

        $fullPrefix = $this->buildFullKey($normPrefix);

        if (self::$mockEnv !== null) {
            $deleted = 0;
            if (isset(self::$mockEnv['storage']) && is_array(self::$mockEnv['storage'])) {
                foreach (array_keys(self::$mockEnv['storage']) as $k) {
                    if (str_starts_with($k, $fullPrefix . '/')) {
                        unset(self::$mockEnv['storage'][$k]);
                        $deleted++;
                    }
                }
            }
            return $deleted;
        }

        // List and delete matching objects in prefix
        $objects = $this->listObjectsUnderPrefix($fullPrefix);
        $count = 0;
        foreach ($objects as $objKey) {
            $res = $this->sendRequest('DELETE', $objKey);
            if ($res['status'] >= 200 && $res['status'] < 300) {
                $count++;
            }
        }

        return $count;
    }

    public function url(string $key): string
    {
        $fullKey = $this->buildFullKey($key);
        if ($this->cdnBaseUrl !== null && $this->cdnBaseUrl !== '') {
            return $this->cdnBaseUrl . '/' . ltrim($fullKey, '/');
        }

        return $this->buildObjectEndpointUrl($fullKey);
    }

    public function temporaryUrl(string $key, int $ttlSeconds = 300, array $options = []): ?string
    {
        $fullKey = $this->buildFullKey($key);
        if ($fullKey === '') {
            return null;
        }

        $ttl = max(60, min(86400, $ttlSeconds)); // Clamped safe TTL

        if (self::$mockEnv !== null) {
            $exp = time() + $ttl;
            return "https://mock-s3.example.com/{$this->bucket}/{$fullKey}?X-Amz-Signature=mock_sig_{$exp}&X-Amz-Expires={$ttl}";
        }

        // Generate SigV4 Presigned GET URL
        return $this->generatePresignedSigV4Url('GET', $fullKey, $ttl, $options);
    }

    public function testConnection(): array
    {
        if (self::$mockEnv !== null) {
            $available = self::$mockEnv['available'] ?? true;
            return [
                'success' => $available,
                'message' => $available ? "S3 mock bucket is reachable." : "S3 mock connection failed.",
                'details' => ['driver' => 's3', 'bucket' => $this->bucket, 'region' => $this->region],
            ];
        }

        if (empty($this->bucket) || empty($this->accessKey) || empty($this->secretKey)) {
            return [
                'success' => false,
                'message' => "S3 configuration incomplete: Bucket, Access Key, and Secret Key are required.",
                'details' => ['driver' => 's3'],
            ];
        }

        $probeKey = $this->buildFullKey('.probe_' . uniqid());
        $putRes = $this->sendRequest('PUT', $probeKey, 'probe', ['Content-Type' => 'text/plain']);
        if ($putRes['status'] < 200 || $putRes['status'] >= 300) {
            return [
                'success' => false,
                'message' => "S3 connectivity probe failed: HTTP {$putRes['status']} - " . substr($putRes['body'], 0, 150),
                'details' => ['driver' => 's3', 'status' => $putRes['status']],
            ];
        }

        // Clean probe file
        $this->sendRequest('DELETE', $probeKey);

        return [
            'success' => true,
            'message' => "S3 connection successful. Bucket is accessible and writable.",
            'details' => [
                'driver'   => 's3',
                'bucket'   => $this->bucket,
                'region'   => $this->region,
                'endpoint' => $this->endpoint,
            ],
        ];
    }

    protected function buildObjectEndpointUrl(string $fullKey): string
    {
        $cleanEndpoint = rtrim($this->endpoint, '/');
        if ($this->usePathStyle) {
            return "{$cleanEndpoint}/{$this->bucket}/" . ltrim($fullKey, '/');
        }

        // Virtual hosted style
        $parsed = parse_url($cleanEndpoint);
        $scheme = $parsed['scheme'] ?? 'https';
        $host   = $parsed['host'] ?? 's3.amazonaws.com';
        $port   = isset($parsed['port']) ? ":{$parsed['port']}" : '';
        return "{$scheme}://{$this->bucket}.{$host}{$port}/" . ltrim($fullKey, '/');
    }

    public function generatePresignedSigV4Url(string $httpMethod, string $fullKey, int $ttlSeconds, array $options = []): string
    {
        $now = time();
        $amzDate = gmdate('Ymd\THis\Z', $now);
        $dateStamp = gmdate('Ymd', $now);

        $baseUrl = $this->buildObjectEndpointUrl($fullKey);
        $parsed = parse_url($baseUrl);
        $host = $parsed['host'] . (isset($parsed['port']) ? ":{$parsed['port']}" : '');
        $path = $parsed['path'] ?? '/';

        $credentialScope = "{$dateStamp}/{$this->region}/s3/aws4_request";

        $queryParams = [
            'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential'    => "{$this->accessKey}/{$credentialScope}",
            'X-Amz-Date'          => $amzDate,
            'X-Amz-Expires'       => (string)$ttlSeconds,
            'X-Amz-SignedHeaders' => 'host',
        ];

        if (!empty($options['download_name'])) {
            $safeName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $options['download_name']);
            $queryParams['response-content-disposition'] = "attachment; filename=\"{$safeName}\"";
        }

        ksort($queryParams);
        $canonicalQueryParts = [];
        foreach ($queryParams as $k => $v) {
            $canonicalQueryParts[] = rawurlencode((string)$k) . '=' . rawurlencode((string)$v);
        }
        $canonicalQuery = implode('&', $canonicalQueryParts);

        $canonicalHeaders = "host:{$host}\n";
        $signedHeaders = "host";

        $canonicalRequest = implode("\n", [
            $httpMethod,
            $path,
            $canonicalQuery,
            $canonicalHeaders,
            $signedHeaders,
            'UNSIGNED-PAYLOAD',
        ]);

        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        $signingKey = $this->getSignatureKey($this->secretKey, $dateStamp, $this->region, 's3');
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        return "{$baseUrl}?{$canonicalQuery}&X-Amz-Signature={$signature}";
    }

    protected function sendRequest(string $method, string $fullKey, string $body = '', array $extraHeaders = []): array
    {
        $url = $this->buildObjectEndpointUrl($fullKey);
        $parsed = parse_url($url);
        $host = $parsed['host'] . (isset($parsed['port']) ? ":{$parsed['port']}" : '');
        $path = $parsed['path'] ?? '/';

        $now = time();
        $amzDate = gmdate('Ymd\THis\Z', $now);
        $dateStamp = gmdate('Ymd', $now);

        $payloadHash = hash('sha256', $body);

        $headers = array_merge([
            'Host'                 => $host,
            'x-amz-date'           => $amzDate,
            'x-amz-content-sha256' => $payloadHash,
        ], $extraHeaders);

        if ($method === 'PUT' || $method === 'POST') {
            $headers['Content-Length'] = (string)strlen($body);
        }

        // Canonical headers
        $lowerHeaders = [];
        foreach ($headers as $k => $v) {
            $lowerHeaders[strtolower((string)$k)] = trim((string)$v);
        }
        ksort($lowerHeaders);

        $canonicalHeaders = '';
        foreach ($lowerHeaders as $k => $v) {
            $canonicalHeaders .= "{$k}:{$v}\n";
        }
        $signedHeaders = implode(';', array_keys($lowerHeaders));

        $canonicalRequest = implode("\n", [
            $method,
            $path,
            '', // query string
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $credentialScope = "{$dateStamp}/{$this->region}/s3/aws4_request";
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        $signingKey = $this->getSignatureKey($this->secretKey, $dateStamp, $this->region, 's3');
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $authHeader = "AWS4-HMAC-SHA256 Credential={$this->accessKey}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";
        $headers['Authorization'] = $authHeader;

        // Perform HTTP request using stream context
        $formattedHeaders = [];
        foreach ($headers as $k => $v) {
            $formattedHeaders[] = "{$k}: {$v}";
        }

        $ctx = stream_context_create([
            'http' => [
                'method'        => $method,
                'header'        => implode("\r\n", $formattedHeaders),
                'content'       => $body,
                'ignore_errors' => true,
                'timeout'       => 15,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $responseBody = @file_get_contents($url, false, $ctx);
        $statusCode = 0;
        $responseHeaders = [];

        $rawHeaders = function_exists('http_get_last_response_headers')
            ? http_get_last_response_headers()
            : ($http_response_header ?? null);

        if (is_array($rawHeaders)) {
            foreach ($rawHeaders as $h) {
                if (preg_match('#HTTP/\S+\s+(\d+)#', $h, $m)) {
                    $statusCode = (int)$m[1];
                } elseif (str_contains($h, ':')) {
                    [$hk, $hv] = explode(':', $h, 2);
                    $responseHeaders[strtolower(trim($hk))] = trim($hv);
                }
            }
        }

        return [
            'status'  => $statusCode,
            'body'    => (string)($responseBody !== false ? $responseBody : ''),
            'headers' => $responseHeaders,
        ];
    }

    protected function getSignatureKey(string $key, string $date, string $region, string $service): string
    {
        $kDate    = hash_hmac('sha256', $date, 'AWS4' . $key, true);
        $kRegion  = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }

    protected function listObjectsUnderPrefix(string $prefix): array
    {
        $url = $this->buildObjectEndpointUrl('') . '?list-type=2&prefix=' . rawurlencode($prefix);
        $parsed = parse_url($url);
        $host = $parsed['host'] . (isset($parsed['port']) ? ":{$parsed['port']}" : '');

        $now = time();
        $amzDate = gmdate('Ymd\THis\Z', $now);
        $dateStamp = gmdate('Ymd', $now);

        $credentialScope = "{$dateStamp}/{$this->region}/s3/aws4_request";
        $headers = [
            'Host'                 => $host,
            'x-amz-date'           => $amzDate,
            'x-amz-content-sha256' => hash('sha256', ''),
        ];

        $canonicalRequest = "GET\n{$parsed['path']}\nlist-type=2&prefix=" . rawurlencode($prefix) . "\nhost:{$host}\nx-amz-content-sha256:{$headers['x-amz-content-sha256']}\nx-amz-date:{$amzDate}\n\nhost;x-amz-content-sha256;x-amz-date\n" . hash('sha256', '');
        $stringToSign = "AWS4-HMAC-SHA256\n{$amzDate}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);
        $signingKey = $this->getSignatureKey($this->secretKey, $dateStamp, $this->region, 's3');
        $sig = hash_hmac('sha256', $stringToSign, $signingKey);

        $headers['Authorization'] = "AWS4-HMAC-SHA256 Credential={$this->accessKey}/{$credentialScope}, SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature={$sig}";

        $hdr = [];
        foreach ($headers as $k => $v) {
            $hdr[] = "{$k}: {$v}";
        }

        $ctx = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'header'        => implode("\r\n", $hdr),
                'ignore_errors' => true,
                'timeout'       => 15,
            ],
        ]);

        $xml = @file_get_contents($url, false, $ctx);
        if (!$xml) {
            return [];
        }

        $keys = [];
        if (preg_match_all('#<Key>(.*?)</Key>#', $xml, $matches)) {
            $keys = $matches[1];
        }

        return $keys;
    }

    protected function detectMimeType(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'm3u8'  => 'application/vnd.apple.mpegurl',
            'ts'    => 'video/mp2t',
            'm4s'   => 'video/iso.segment',
            'mp4'   => 'video/mp4',
            'webm'  => 'video/webm',
            'mp3'   => 'audio/mpeg',
            'm4a'   => 'audio/mp4',
            'vtt'   => 'text/vtt',
            'jpg', 'jpeg' => 'image/jpeg',
            'png'   => 'image/png',
            'webp'  => 'image/webp',
            default => 'application/octet-stream',
        };
    }
}
