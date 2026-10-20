<?php

function plugin_station_validate_order_multiplier($value): float
{
    $text = is_float($value) ? json_encode($value, JSON_PRESERVE_ZERO_FRACTION) : $value;
    if ((!is_string($value) && !is_int($value) && !is_float($value))
        || !preg_match('/^(?:0|[1-9]\d{0,2})(?:\.\d{1,4})?$/D', (string)$text)
        || (float)$value < 0.01 || (float)$value > 100) {
        throw new InvalidArgumentException('下单倍率必须为 0.01~100，最多 4 位小数');
    }
    return (float)$value;
}

function plugin_station_pricing_data_dir(): string
{
    $dir = dirname(__DIR__) . '/data';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('定价数据目录不可写');
    }
    return $dir;
}

function plugin_station_read_pricing_settings(): array
{
    $path = dirname(__DIR__) . '/data/pricing-settings.php';
    if (!file_exists($path)) {
        return ['order_multiplier' => 1.0];
    }
    $content = @file_get_contents($path);
    $prefix = "<?php exit; ?>\n";
    if (!is_string($content) || substr($content, 0, strlen($prefix)) !== $prefix) {
        throw new RuntimeException('定价设置不可读或已损坏，请联系管理员');
    }
    $settings = json_decode(substr($content, strlen($prefix)), true);
    try {
        $multiplier = plugin_station_validate_order_multiplier(is_array($settings) ? ($settings['order_multiplier'] ?? null) : null);
    } catch (InvalidArgumentException $e) {
        throw new RuntimeException('定价设置已损坏，请联系管理员');
    }
    return ['order_multiplier' => $multiplier];
}

function plugin_station_order_multiplier(): float
{
    try {
        return plugin_station_read_pricing_settings()['order_multiplier'];
    } catch (RuntimeException $e) {
        plugin_station_json(['success' => false, 'message' => $e->getMessage()], 500);
        exit;
    }
}

function plugin_station_write_pricing_settings(float $multiplier): void
{
    $dir = plugin_station_pricing_data_dir();
    $content = "<?php exit; ?>\n" . json_encode(['order_multiplier' => $multiplier], JSON_UNESCAPED_SLASHES) . "\n";
    $temporary = tempnam($dir, '.pricing-');
    if ($temporary === false) {
        throw new RuntimeException('定价设置暂存失败');
    }
    try {
        if (!chmod($temporary, 0600)
            || file_put_contents($temporary, $content, LOCK_EX) !== strlen($content)
            || !rename($temporary, $dir . '/pricing-settings.php')) {
            throw new RuntimeException('定价设置保存失败');
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
}

function plugin_station_pricing_csrf_token(array $user, int $expires): string
{
    $config = plugin_station_config();
    $secret = (string)($config['app_secret'] ?? '');
    if ($secret === '') {
        throw new RuntimeException('服务端签名配置未就绪');
    }
    $identity = json_encode(['pricing_settings', (string)($config['site_id'] ?? ''), (int)$user['uid'], (string)($user['uuid'] ?? ''), $expires]);
    return $expires . '.' . hash_hmac('sha256', $identity, $secret);
}

function plugin_station_handle_pricing_settings(string $action): void
{
    $user = plugin_station_require_logged_in_user();
    if (!plugin_station_is_admin_user($user)) {
        plugin_station_json(['success' => false, 'message' => '仅 uid=1 管理员可管理定价'], 403);
    }
    $method = $action === 'pricing_settings' ? 'GET' : 'POST';
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
        header('Allow: ' . $method);
        plugin_station_json(['success' => false, 'message' => '请求方法不允许'], 405);
    }
    header('Cache-Control: no-store');
    try {
        if ($method === 'GET') {
            $data = plugin_station_read_pricing_settings();
            $data['csrf_token'] = plugin_station_pricing_csrf_token($user, time() + 7200);
        } else {
            $contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
            if ($contentType !== 'application/json') {
                plugin_station_json(['success' => false, 'message' => '必须使用 application/json'], 415);
            }
            $payload = json_decode(file_get_contents('php://input'), true);
            if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
                plugin_station_json(['success' => false, 'message' => 'JSON 请求体无效'], 400);
            }
            $token = $payload['csrf_token'] ?? null;
            if (!is_string($token) || !preg_match('/^(\d{10})\.[a-f0-9]{64}$/D', $token, $matches)
                || (int)$matches[1] < time() || (int)$matches[1] > time() + 7200
                || !hash_equals(plugin_station_pricing_csrf_token($user, (int)$matches[1]), $token)) {
                plugin_station_json(['success' => false, 'message' => 'CSRF 校验失败，请刷新设置'], 403);
            }
            $multiplier = plugin_station_validate_order_multiplier($payload['order_multiplier'] ?? null);
            plugin_station_write_pricing_settings($multiplier);
            $data = ['order_multiplier' => $multiplier];
        }
        plugin_station_json(['success' => true, 'message' => 'ok', 'data' => $data]);
    } catch (InvalidArgumentException $e) {
        plugin_station_json(['success' => false, 'message' => $e->getMessage()], 400);
    } catch (RuntimeException $e) {
        plugin_station_json(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

function plugin_station_apply_catalog_pricing(array $result): array
{
    if (!($result['success'] ?? false) || !is_array($result['data'] ?? null)) {
        return $result;
    }
    $multiplier = plugin_station_order_multiplier();
    $convert = function (array $items) use (&$convert, $multiplier): array {
        foreach ($items as $key => $value) {
            if (is_array($value)) {
                $items[$key] = $convert($value);
            } elseif (in_array($key, ['daily_unit_price', 'morning_unit_price', 'dailyUnitPrice', 'morningUnitPrice'], true) && is_numeric($value)) {
                $items[$key] = round((float)$value * $multiplier, 8);
            }
        }
        return $items;
    };
    $result['data'] = $convert($result['data']);
    $result['order_multiplier'] = $multiplier;
    return $result;
}

function plugin_station_price_snapshot(int $gatewayCents, float $multiplier, array $user): array
{
    $saleCents = (int)round($gatewayCents * $multiplier);
    $chargeCents = $saleCents;
    $channelFile = plugin_station_host_root() . '/sport/zhiya_channel.php';
    if (is_file($channelFile)) {
        require_once $channelFile;
    }
    $hostDb = plugin_station_host_db();
    if ($hostDb && function_exists('zhiya_channel_price')) {
        $discounted = zhiya_channel_price($hostDb, (int)$user['uid'], 'channel:laoda', $saleCents / 100);
        if (!is_numeric($discounted) || !is_finite((float)$discounted) || (float)$discounted < 0) {
            throw new RuntimeException('质押折扣价格无效');
        }
        $chargeCents = min($saleCents, (int)round((float)$discounted * 100));
    }
    return [
        'order_multiplier' => $multiplier,
        'gateway_price_cents' => $gatewayCents,
        'host_price_cents' => $saleCents,
        'host_charge_cents' => $chargeCents,
        'host_discount_cents' => $saleCents - $chargeCents,
    ];
}

function plugin_station_pricing_snapshot_fields(): array
{
    return ['order_multiplier', 'gateway_price_cents', 'host_price_cents', 'host_charge_cents', 'host_discount_cents'];
}

function plugin_station_restore_order_pricing(array $order): array
{
    $payload = json_decode((string)($order['payload_json'] ?? ''), true);
    unset($order['payload_json']);
    if (is_array($payload)) {
        foreach (plugin_station_pricing_snapshot_fields() as $key) {
            if (array_key_exists($key, $payload)) {
                $order[$key] = $payload[$key];
            }
        }
    }
    return plugin_station_order_customer_amounts($order);
}

function plugin_station_order_customer_amounts(array $order): array
{
    $charged = (int)round((float)($order['host_charge_amount'] ?? 0) * 100);
    $order['host_price_cents'] = $order['host_price_cents'] ?? ((int)($order['host_charge_applied'] ?? 0) === 1 ? $charged : (int)($order['price_cents'] ?? 0));
    $order['host_charge_cents'] = $charged;
    $order['customer_refunded_cents'] = (int)round((float)($order['host_refund_amount'] ?? 0) * 100);
    return $order;
}

function plugin_station_order_sync_lock(string $traceId)
{
    if ($traceId === '') {
        throw new RuntimeException('订单缺少 trace_id，无法同步');
    }
    $path = plugin_station_pricing_data_dir() . '/order-sync-' . hash('sha256', $traceId) . '.lock';
    $handle = fopen($path, 'c');
    if ($handle === false) {
        throw new RuntimeException('订单同步锁不可用');
    }
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        throw new RuntimeException('订单同步加锁失败');
    }
    return $handle;
}
