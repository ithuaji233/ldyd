<?php

require_once __DIR__ . '/bootstrap.php';

function plugin_station_starts_with(string $haystack, string $needle): bool
{
    if ($needle === '') {
        return true;
    }

    return strpos($haystack, $needle) === 0;
}

function plugin_station_debug_config(): ?array
{
    static $config = null;
    static $loaded = false;
    if ($loaded) {
        return $config;
    }

    $loaded = true;
    $envPath = dirname(__DIR__) . '/.dbg/plugin-debug.env';
    if (!is_file($envPath)) {
        $config = null;
        return null;
    }

    $debugUrl = 'http://127.0.0.1:7778/event';
    $sessionId = 'plugin-debug';
    $content = @file_get_contents($envPath);
    if (is_string($content) && $content !== '') {
        foreach (preg_split('/\r?\n/', $content) as $line) {
            if (plugin_station_starts_with($line, 'DEBUG_SERVER_URL=')) {
                $debugUrl = trim(substr($line, strlen('DEBUG_SERVER_URL=')));
            } elseif (plugin_station_starts_with($line, 'DEBUG_SESSION_ID=')) {
                $sessionId = trim(substr($line, strlen('DEBUG_SESSION_ID=')));
            }
        }
    }

    $config = [
        'debug_url' => $debugUrl,
        'session_id' => $sessionId,
    ];
    return $config;
}

// #region debug-point helper:plugin-debug
function plugin_station_debug_report(string $hypothesisId, string $location, string $msg, array $data = [], string $runId = 'post-fix'): void
{
    $debugConfig = plugin_station_debug_config();
    if ($debugConfig === null) {
        return;
    }
    $payload = json_encode([
        'sessionId' => $debugConfig['session_id'],
        'runId' => $runId,
        'hypothesisId' => $hypothesisId,
        'location' => $location,
        'msg' => $msg,
        'data' => $data,
        'ts' => (int)round(microtime(true) * 1000),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        return;
    }
    @file_get_contents($debugConfig['debug_url'], false, stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nConnection: close\r\n",
            'content' => $payload,
            'timeout' => 0.1,
            'ignore_errors' => true,
        ],
    ]));
}
// #endregion

function plugin_station_nonce(): string
{
    return bin2hex(random_bytes(8));
}

function plugin_station_build_signature(array $payload, int $timestamp, string $nonce, string $appSecret): string
{
    return hash_hmac(
        'sha256',
        $timestamp . '.' . $nonce . '.' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $appSecret
    );
}

function plugin_station_normalize_get_signature_payload(array $payload): array
{
    $query = http_build_query($payload);
    if ($query === '') {
        return [];
    }
    parse_str($query, $normalized);
    return is_array($normalized) ? $normalized : [];
}

function plugin_station_gateway_max_attempts(): int
{
    return 2;
}

function plugin_station_gateway_connect_timeout_seconds(): int
{
    return 3;
}

function plugin_station_gateway_timeout_seconds(): int
{
    return 20;
}

function plugin_station_gateway_request(string $method, string $path, array $payload = []): array
{
    $config = plugin_station_config();
    $url = rtrim($config['gateway_base_url'], '/') . $path;
    $attempt = 0;
    $maxAttempts = plugin_station_gateway_max_attempts();
    $connectTimeout = plugin_station_gateway_connect_timeout_seconds();
    $timeout = plugin_station_gateway_timeout_seconds();
    $lastError = [
        'success' => false,
        'code' => 500,
        'message' => '网关请求失败',
    ];

    while ($attempt < $maxAttempts) {
        $attempt++;
        $requestStartedAt = microtime(true);
        $timestamp = (int) round(microtime(true) * 1000);
        $nonce = plugin_station_nonce();
        $signaturePayload = strtoupper($method) === 'GET'
            ? plugin_station_normalize_get_signature_payload($payload)
            : $payload;
        $signature = plugin_station_build_signature($signaturePayload, $timestamp, $nonce, $config['app_secret']);
        $ch = curl_init();
        $shouldDebug = plugin_station_debug_config() !== null
            && (plugin_station_starts_with($path, '/open/plugin/tickets')
                || plugin_station_starts_with($path, '/open/plugin/orders'));

        if ($shouldDebug) {
            // #region debug-point B:gateway-request-start
            plugin_station_debug_report('B', 'plugin-station/lib/client.php:plugin_station_gateway_request:start', '[DEBUG] plugin gateway request start', [
                'path' => $path,
                'method' => strtoupper($method),
                'attempt' => $attempt,
                'site_id' => (string)($payload['site_id'] ?? ''),
                'ticket_id' => (string)($payload['ticket_id'] ?? ''),
                'trace_id' => (string)($payload['trace_id'] ?? ''),
                'current_user_uid' => (int)($payload['current_user_uid'] ?? 0),
            ]);
            // #endregion
        }

        if (strtoupper($method) === 'GET') {
            $query = http_build_query(array_merge($payload, [
                'app_key' => $config['app_key'],
                'timestamp' => $timestamp,
                'nonce' => $nonce,
                'signature' => $signature,
            ]));
            curl_setopt_array($ch, [
                CURLOPT_URL => $url . '?' . $query,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => $connectTimeout,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_ENCODING => '',
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_0,
                CURLOPT_NOSIGNAL => true,
            CURLOPT_FRESH_CONNECT => true,
            CURLOPT_FORBID_REUSE => true,
            CURLOPT_HTTPHEADER => [
                'Connection: close',
            ],
            ]);
        } else {
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode([
                    'app_key' => $config['app_key'],
                    'timestamp' => $timestamp,
                    'nonce' => $nonce,
                    'signature' => $signature,
                    'payload' => $payload,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                'Connection: close',
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => $connectTimeout,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_ENCODING => '',
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_0,
                CURLOPT_NOSIGNAL => true,
            CURLOPT_FRESH_CONNECT => true,
            CURLOPT_FORBID_REUSE => true,
            ]);
        }

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($shouldDebug) {
            // #region debug-point B:gateway-request-finished
            plugin_station_debug_report('B', 'plugin-station/lib/client.php:plugin_station_gateway_request:finish', '[DEBUG] plugin gateway request finish', [
                'path' => $path,
                'method' => strtoupper($method),
                'attempt' => $attempt,
                'duration_ms' => (int)round((microtime(true) - $requestStartedAt) * 1000),
                'curl_errno' => $errno,
                'curl_error' => $error,
                'status_code' => $statusCode,
                'raw_prefix' => is_string($raw) ? substr($raw, 0, 180) : '',
            ]);
            // #endregion
        }

        if ($errno !== 0) {
            if ($shouldDebug) {
                // #region debug-point B:gateway-request-curl-error
                plugin_station_debug_report('B', 'plugin-station/lib/client.php:plugin_station_gateway_request:curl-error', '[DEBUG] plugin gateway request curl error', [
                    'path' => $path,
                    'method' => strtoupper($method),
                    'attempt' => $attempt,
                    'curl_errno' => $errno,
                    'curl_error' => $error,
                ], 'pre-fix');
                // #endregion
            }
            $lastError = [
                'success' => false,
                'code' => 500,
                'message' => '网关请求失败: ' . $error,
            ];
            if ($attempt < $maxAttempts) {
                usleep(200000 * $attempt);
                continue;
            }
            return $lastError;
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            if ($shouldDebug) {
                // #region debug-point B:gateway-request-invalid-json
                plugin_station_debug_report('B', 'plugin-station/lib/client.php:plugin_station_gateway_request:invalid-json', '[DEBUG] plugin gateway request invalid json', [
                    'path' => $path,
                    'method' => strtoupper($method),
                    'attempt' => $attempt,
                    'status_code' => $statusCode,
                    'raw_prefix' => is_string($raw) ? substr($raw, 0, 300) : '',
                ], 'pre-fix');
                // #endregion
            }
            $lastError = [
                'success' => false,
                'code' => 500,
                'message' => '网关返回格式错误',
                'raw' => $raw,
            ];
            if ($attempt < $maxAttempts) {
                usleep(200000 * $attempt);
                continue;
            }
            return $lastError;
        }

        if ($shouldDebug) {
            // #region debug-point B:gateway-request-decoded
            plugin_station_debug_report('B', 'plugin-station/lib/client.php:plugin_station_gateway_request:decoded', '[DEBUG] plugin gateway request decoded response', [
                'path' => $path,
                'method' => strtoupper($method),
                'attempt' => $attempt,
                'status_code' => $statusCode,
                'success' => (bool)($decoded['success'] ?? false),
                'code' => (int)($decoded['code'] ?? 0),
                'message' => (string)($decoded['message'] ?? ''),
                'data_type' => gettype($decoded['data'] ?? null),
                'data_count' => is_array($decoded['data'] ?? null) ? count($decoded['data']) : -1,
            ], 'pre-fix');
            // #endregion
        }

        if ($statusCode >= 500 && $attempt < $maxAttempts) {
            $lastError = [
                'success' => false,
                'code' => $decoded['code'] ?? $statusCode,
                'message' => $decoded['message'] ?? '网关请求失败',
                'data' => $decoded['data'] ?? null,
            ];
            usleep(200000 * $attempt);
            continue;
        }

        if ($statusCode >= 400) {
            return [
                'success' => false,
                'code' => $decoded['code'] ?? $statusCode,
                'message' => $decoded['message'] ?? '网关请求失败',
                'data' => $decoded['data'] ?? null,
            ];
        }

        return $decoded;
    }

    return $lastError;
}
