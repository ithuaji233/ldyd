<?php

function plugin_station_raw_config(): array
{
    static $config = null;
    if ($config === null) {
        $config = require dirname(__DIR__) . '/config.php';
    }
    return $config;
}

function plugin_station_plugin_root(): string
{
    return dirname(__DIR__);
}

function plugin_station_config_search_depth(): int
{
    return 5;
}

function plugin_station_find_common_candidates(string $baseDir): array
{
    $normalized = rtrim($baseDir, '/\\');
    if ($normalized === '') {
        return [];
    }

    return [
        $normalized . '/confing/common.php',
        $normalized . '/common.php',
        $normalized . '/db/common.php',
    ];
}

function plugin_station_search_common_php(string $startPath): ?string
{
    $currentPath = rtrim($startPath, '/\\');
    $maxDepth = plugin_station_config_search_depth();
    $depth = 0;

    while ($currentPath !== '' && $currentPath !== '/' && $depth <= $maxDepth) {
        foreach (plugin_station_find_common_candidates($currentPath) as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        $parentPath = dirname($currentPath);
        if ($parentPath === $currentPath) {
            break;
        }

        $currentPath = $parentPath;
        $depth++;
    }

    return null;
}

function plugin_station_host_common_path(): string
{
    $config = plugin_station_raw_config();
    if (!empty($config['host_common_path'])) {
        return (string) $config['host_common_path'];
    }
    if (!empty($config['host_root'])) {
        return rtrim((string) $config['host_root'], '/\\') . '/confing/common.php';
    }

    $pluginRoot = plugin_station_plugin_root();
    $detectedPath = plugin_station_search_common_php($pluginRoot);
    if ($detectedPath !== null) {
        return $detectedPath;
    }

    return $pluginRoot . '/confing/common.php';
}

function plugin_station_host_root(): string
{
    $commonPath = plugin_station_host_common_path();
    if (basename(dirname($commonPath)) === 'confing') {
        return dirname(dirname($commonPath));
    }
    return dirname($commonPath);
}

function plugin_station_boot_host_context(): array
{
    static $context = null;
    if ($context !== null) {
        return $context;
    }

    $context = [
        'loaded' => false,
        'islogin' => 0,
        'userrow' => null,
        'conf' => [],
        'db' => null,
    ];

    $commonPath = plugin_station_host_common_path();
    if (is_file($commonPath)) {
        require_once $commonPath;
        $context['loaded'] = true;
        $context['islogin'] = isset($islogin) ? (int)$islogin : 0;
        $context['userrow'] = isset($userrow) && is_array($userrow) ? $userrow : null;
        $context['conf'] = isset($conf) && is_array($conf) ? $conf : [];
        $context['db'] = isset($DB) ? $DB : null;
        if (isset($DB)) {
            $GLOBALS['DB'] = $DB;
        }
        if (isset($userrow)) {
            $GLOBALS['userrow'] = $userrow;
        }
        if (isset($conf)) {
            $GLOBALS['conf'] = $conf;
        }
        if (isset($islogin)) {
            $GLOBALS['islogin'] = $islogin;
        }
        if (isset($clientip)) {
            $GLOBALS['clientip'] = $clientip;
        }
        if (function_exists('session_status') && function_exists('session_write_close') && session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    return $context;
}

function plugin_station_config(): array
{
    static $config = null;
    if ($config === null) {
        $config = plugin_station_raw_config();
        $hostContext = plugin_station_boot_host_context();
        if (!empty($hostContext['conf']['sitename'])) {
            $config['site_name'] = $hostContext['conf']['sitename'];
        }
    }
    return $config;
}

function plugin_station_now(): string
{
    return gmdate('c');
}

function plugin_station_host_db()
{
    $hostContext = plugin_station_boot_host_context();
    return $hostContext['db'] ?? null;
}

function plugin_station_money_from_cents(int $amountCents): float
{
    return round($amountCents / 100, 2);
}

function plugin_station_money_sql(float $amount): string
{
    return number_format($amount, 2, '.', '');
}

function plugin_station_db(): SQLite3
{
    static $db = null;
    if ($db instanceof SQLite3) {
        return $db;
    }

    $config = plugin_station_config();
    $dir = dirname($config['db_path']);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $db = new SQLite3($config['db_path']);
    @chmod($config['db_path'], 0666);
    $db->exec('PRAGMA journal_mode = WAL;');
    @chmod($config['db_path'] . '-wal', 0666);
    @chmod($config['db_path'] . '-shm', 0666);
    $db->exec('CREATE TABLE IF NOT EXISTS plugin_local_orders (
        trace_id TEXT PRIMARY KEY,
        site_id TEXT DEFAULT "",
        source_order_id TEXT DEFAULT "",
        host_user_uid INTEGER NOT NULL DEFAULT 0,
        host_username TEXT DEFAULT "",
        host_display_name TEXT DEFAULT "",
        host_user_uuid TEXT DEFAULT "",
        account TEXT NOT NULL,
        password TEXT DEFAULT "",
        school_name TEXT NOT NULL,
        platform TEXT NOT NULL,
        target_km REAL NOT NULL DEFAULT 0,
        price_cents INTEGER NOT NULL DEFAULT 0,
        status TEXT NOT NULL,
        status_message TEXT DEFAULT "",
        host_charge_amount REAL NOT NULL DEFAULT 0,
        host_charge_applied INTEGER NOT NULL DEFAULT 0,
        refunded_cents INTEGER NOT NULL DEFAULT 0,
        refund_count INTEGER NOT NULL DEFAULT 0,
        refund_reason TEXT DEFAULT "",
        last_refund_trade_no TEXT DEFAULT "",
        refund_updated_at TEXT DEFAULT "",
        main_order_status TEXT DEFAULT "",
        current_km REAL NOT NULL DEFAULT 0,
        main_target_km REAL NOT NULL DEFAULT 0,
        status_query_updated_at TEXT DEFAULT "",
        host_refund_amount REAL NOT NULL DEFAULT 0,
        host_refund_count INTEGER NOT NULL DEFAULT 0,
        payload_json TEXT DEFAULT "",
        created_at TEXT DEFAULT "",
        updated_at TEXT NOT NULL
    )');
    plugin_station_ensure_local_order_column($db, 'site_id', 'TEXT DEFAULT ""');
    plugin_station_ensure_local_order_column($db, 'host_user_uid', 'INTEGER NOT NULL DEFAULT 0');
    plugin_station_ensure_local_order_column($db, 'host_username', 'TEXT DEFAULT ""');
    plugin_station_ensure_local_order_column($db, 'host_display_name', 'TEXT DEFAULT ""');
    plugin_station_ensure_local_order_column($db, 'host_user_uuid', 'TEXT DEFAULT ""');
    plugin_station_ensure_local_order_column($db, 'password', 'TEXT DEFAULT ""');
    plugin_station_ensure_local_order_column($db, 'price_cents', 'INTEGER NOT NULL DEFAULT 0');
    plugin_station_ensure_local_order_column($db, 'host_charge_amount', 'REAL NOT NULL DEFAULT 0');
    plugin_station_ensure_local_order_column($db, 'host_charge_applied', 'INTEGER NOT NULL DEFAULT 0');
    plugin_station_ensure_local_order_column($db, 'refunded_cents', 'INTEGER NOT NULL DEFAULT 0');
    plugin_station_ensure_local_order_column($db, 'refund_count', 'INTEGER NOT NULL DEFAULT 0');
    plugin_station_ensure_local_order_column($db, 'refund_reason', 'TEXT DEFAULT ""');
    plugin_station_ensure_local_order_column($db, 'last_refund_trade_no', 'TEXT DEFAULT ""');
    plugin_station_ensure_local_order_column($db, 'refund_updated_at', 'TEXT DEFAULT ""');
    plugin_station_ensure_local_order_column($db, 'main_order_status', 'TEXT DEFAULT ""');
    plugin_station_ensure_local_order_column($db, 'current_km', 'REAL NOT NULL DEFAULT 0');
    plugin_station_ensure_local_order_column($db, 'main_target_km', 'REAL NOT NULL DEFAULT 0');
    plugin_station_ensure_local_order_column($db, 'status_query_updated_at', 'TEXT DEFAULT ""');
    plugin_station_ensure_local_order_column($db, 'host_refund_amount', 'REAL NOT NULL DEFAULT 0');
    plugin_station_ensure_local_order_column($db, 'host_refund_count', 'INTEGER NOT NULL DEFAULT 0');
    plugin_station_ensure_local_order_column($db, 'created_at', 'TEXT DEFAULT ""');
    $db->exec('CREATE TABLE IF NOT EXISTS plugin_meta_cache (
        cache_key TEXT PRIMARY KEY,
        payload_json TEXT NOT NULL,
        fetched_at INTEGER NOT NULL DEFAULT 0,
        updated_at TEXT NOT NULL
    )');

    return $db;
}

function plugin_station_ensure_local_order_column(SQLite3 $db, string $columnName, string $definition): void
{
    $result = $db->query('PRAGMA table_info(plugin_local_orders)');
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        if (($row['name'] ?? '') === $columnName) {
            return;
        }
    }
    $db->exec(sprintf('ALTER TABLE plugin_local_orders ADD COLUMN %s %s', $columnName, $definition));
}

function plugin_station_resolve_current_user(): array
{
    $hostContext = plugin_station_boot_host_context();
    $userrow = $hostContext['userrow'];

    if ($hostContext['loaded'] && $hostContext['islogin'] === 1 && is_array($userrow)) {
        return [
            'uid' => (int)($userrow['uid'] ?? 0),
            'username' => (string)($userrow['user'] ?? ''),
            'display_name' => (string)($userrow['name'] ?? ($userrow['user'] ?? '')),
            'uuid' => (string)($userrow['uuid'] ?? ''),
            'money' => isset($userrow['money']) ? (float)$userrow['money'] : 0.0,
            'active' => (string)($userrow['active'] ?? ''),
            'is_test_site_user' => true,
        ];
    }

    return [
        'uid' => 0,
        'username' => '',
        'display_name' => '未登录',
        'uuid' => '',
        'money' => 0.0,
        'active' => '',
        'is_test_site_user' => false,
    ];
}

function plugin_station_is_admin_user(array $currentUser): bool
{
    return (int)($currentUser['uid'] ?? 0) === 1;
}

function plugin_station_current_site_id(): string
{
    return (string)(plugin_station_config()['site_id'] ?? '');
}

function plugin_station_is_order_visible_in_scope(array $order, array $currentUser, ?string $expectedSiteId = null): bool
{
    if (plugin_station_is_admin_user($currentUser)) {
        return true;
    }

    $currentUserUid = (int)($currentUser['uid'] ?? 0);
    if ($currentUserUid <= 0) {
        return false;
    }

    $siteId = trim((string)($expectedSiteId ?? plugin_station_current_site_id()));
    $orderSiteId = trim((string)($order['site_id'] ?? ''));
    if ($siteId !== '' && $orderSiteId !== '' && $siteId !== $orderSiteId) {
        return false;
    }

    return (int)($order['host_user_uid'] ?? 0) === $currentUserUid;
}

function plugin_station_require_logged_in_user(): array
{
    $currentUser = plugin_station_resolve_current_user();
    if ((int)($currentUser['uid'] ?? 0) <= 0) {
        plugin_station_json([
            'success' => false,
            'code' => 401,
            'message' => '请先登录站点账号',
        ], 401);
    }
    return $currentUser;
}

function plugin_station_resolve_site_info(): array
{
    $hostContext = plugin_station_boot_host_context();
    $config = plugin_station_config();

    return [
        'site_name' => (string)($hostContext['conf']['sitename'] ?? $config['site_name']),
        'keywords' => (string)($hostContext['conf']['keywords'] ?? ''),
        'description' => (string)($hostContext['conf']['description'] ?? ''),
        'notice' => (string)($hostContext['conf']['notice'] ?? ''),
        'is_logged_in' => $hostContext['islogin'] === 1,
        'has_host_context' => $hostContext['loaded'],
    ];
}

function plugin_station_save_order(array $order): void
{
    $existing = null;
    if (!empty($order['trace_id'])) {
        // #region debug-point E:save-order-before-load-existing
        plugin_station_debug_report('E', 'plugin-station/lib/bootstrap.php:save-order:before-load-existing', '[DEBUG] plugin save order before load existing', [
            'trace_id' => (string)($order['trace_id'] ?? ''),
        ], 'pre-fix');
        // #endregion
        $existing = plugin_station_get_local_order((string)$order['trace_id']);
        // #region debug-point E:save-order-after-load-existing
        plugin_station_debug_report('E', 'plugin-station/lib/bootstrap.php:save-order:after-load-existing', '[DEBUG] plugin save order after load existing', [
            'trace_id' => (string)($order['trace_id'] ?? ''),
            'has_existing' => is_array($existing),
        ], 'pre-fix');
        // #endregion
    }
    if (is_array($existing)) {
        unset($existing['payload_json']);
        $order = array_merge($existing, $order);
    }

    $db = plugin_station_db();
    $stmt = $db->prepare('
        INSERT INTO plugin_local_orders (
            trace_id, site_id, source_order_id, host_user_uid, host_username, host_display_name, host_user_uuid,
            account, password, school_name, platform, target_km, price_cents, status, status_message,
            host_charge_amount, host_charge_applied, refunded_cents, refund_count, refund_reason,
            last_refund_trade_no, refund_updated_at, main_order_status, current_km, main_target_km, status_query_updated_at,
            host_refund_amount, host_refund_count,
            payload_json, created_at, updated_at
        ) VALUES (
            :trace_id, :site_id, :source_order_id, :host_user_uid, :host_username, :host_display_name, :host_user_uuid,
            :account, :password, :school_name, :platform, :target_km, :price_cents, :status, :status_message,
            :host_charge_amount, :host_charge_applied, :refunded_cents, :refund_count, :refund_reason,
            :last_refund_trade_no, :refund_updated_at, :main_order_status, :current_km, :main_target_km, :status_query_updated_at, :host_refund_amount, :host_refund_count,
            :payload_json, :created_at, :updated_at
        )
        ON CONFLICT(trace_id) DO UPDATE SET
            site_id = excluded.site_id,
            source_order_id = excluded.source_order_id,
            host_user_uid = excluded.host_user_uid,
            host_username = excluded.host_username,
            host_display_name = excluded.host_display_name,
            host_user_uuid = excluded.host_user_uuid,
            account = excluded.account,
            password = excluded.password,
            school_name = excluded.school_name,
            platform = excluded.platform,
            target_km = excluded.target_km,
            price_cents = excluded.price_cents,
            status = excluded.status,
            status_message = excluded.status_message,
            host_charge_amount = excluded.host_charge_amount,
            host_charge_applied = excluded.host_charge_applied,
            refunded_cents = excluded.refunded_cents,
            refund_count = excluded.refund_count,
            refund_reason = excluded.refund_reason,
            last_refund_trade_no = excluded.last_refund_trade_no,
            refund_updated_at = excluded.refund_updated_at,
            main_order_status = excluded.main_order_status,
            current_km = excluded.current_km,
            main_target_km = excluded.main_target_km,
            status_query_updated_at = excluded.status_query_updated_at,
            host_refund_amount = excluded.host_refund_amount,
            host_refund_count = excluded.host_refund_count,
            payload_json = excluded.payload_json,
            created_at = excluded.created_at,
            updated_at = excluded.updated_at
    ');
    // #region debug-point E:save-order-after-prepare
    plugin_station_debug_report('E', 'plugin-station/lib/bootstrap.php:save-order:after-prepare', '[DEBUG] plugin save order after prepare', [
        'trace_id' => (string)($order['trace_id'] ?? ''),
        'prepare_ok' => $stmt instanceof SQLite3Stmt,
        'db_error_code' => $db->lastErrorCode(),
        'db_error_message' => $db->lastErrorMsg(),
    ], 'pre-fix');
    // #endregion

    $stmt->bindValue(':trace_id', $order['trace_id'], SQLITE3_TEXT);
    $stmt->bindValue(':site_id', $order['site_id'] ?? plugin_station_current_site_id(), SQLITE3_TEXT);
    $stmt->bindValue(':source_order_id', $order['source_order_id'] ?? '', SQLITE3_TEXT);
    $stmt->bindValue(':host_user_uid', (int)($order['host_user_uid'] ?? 0), SQLITE3_INTEGER);
    $stmt->bindValue(':host_username', $order['host_username'] ?? '', SQLITE3_TEXT);
    $stmt->bindValue(':host_display_name', $order['host_display_name'] ?? '', SQLITE3_TEXT);
    $stmt->bindValue(':host_user_uuid', $order['host_user_uuid'] ?? '', SQLITE3_TEXT);
    $stmt->bindValue(':account', $order['account'] ?? '', SQLITE3_TEXT);
    $stmt->bindValue(':password', $order['password'] ?? '', SQLITE3_TEXT);
    $stmt->bindValue(':school_name', $order['school_name'] ?? '', SQLITE3_TEXT);
    $stmt->bindValue(':platform', $order['platform'] ?? '', SQLITE3_TEXT);
    $stmt->bindValue(':target_km', (float)($order['target_km'] ?? 0), SQLITE3_FLOAT);
    $stmt->bindValue(':price_cents', (int)($order['price_cents'] ?? 0), SQLITE3_INTEGER);
    $stmt->bindValue(':status', $order['status'] ?? 'unknown', SQLITE3_TEXT);
    $stmt->bindValue(':status_message', $order['status_message'] ?? '', SQLITE3_TEXT);
    $stmt->bindValue(':host_charge_amount', (float)($order['host_charge_amount'] ?? 0), SQLITE3_FLOAT);
    $stmt->bindValue(':host_charge_applied', (int)($order['host_charge_applied'] ?? 0), SQLITE3_INTEGER);
    $stmt->bindValue(':refunded_cents', (int)($order['refunded_cents'] ?? 0), SQLITE3_INTEGER);
    $stmt->bindValue(':refund_count', (int)($order['refund_count'] ?? 0), SQLITE3_INTEGER);
    $stmt->bindValue(':refund_reason', $order['refund_reason'] ?? '', SQLITE3_TEXT);
    $stmt->bindValue(':last_refund_trade_no', $order['last_refund_trade_no'] ?? '', SQLITE3_TEXT);
    $stmt->bindValue(':refund_updated_at', $order['refund_updated_at'] ?? '', SQLITE3_TEXT);
    $stmt->bindValue(':main_order_status', $order['main_order_status'] ?? '', SQLITE3_TEXT);
    $stmt->bindValue(':current_km', (float)($order['current_km'] ?? 0), SQLITE3_FLOAT);
    $stmt->bindValue(':main_target_km', (float)($order['main_target_km'] ?? 0), SQLITE3_FLOAT);
    $stmt->bindValue(':status_query_updated_at', $order['status_query_updated_at'] ?? '', SQLITE3_TEXT);
    $stmt->bindValue(':host_refund_amount', (float)($order['host_refund_amount'] ?? 0), SQLITE3_FLOAT);
    $stmt->bindValue(':host_refund_count', (int)($order['host_refund_count'] ?? 0), SQLITE3_INTEGER);
    $payloadOrder = $order;
    unset($payloadOrder['payload_json']);
    $stmt->bindValue(':payload_json', json_encode($payloadOrder, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), SQLITE3_TEXT);
    $stmt->bindValue(':created_at', $order['created_at'] ?? ($existing['created_at'] ?? plugin_station_now()), SQLITE3_TEXT);
    $stmt->bindValue(':updated_at', plugin_station_now(), SQLITE3_TEXT);
    $executeResult = $stmt->execute();
    // #region debug-point E:save-order-after-execute
    plugin_station_debug_report('E', 'plugin-station/lib/bootstrap.php:save-order:after-execute', '[DEBUG] plugin save order after execute', [
        'trace_id' => (string)($order['trace_id'] ?? ''),
        'execute_ok' => $executeResult instanceof SQLite3Result,
        'db_error_code' => $db->lastErrorCode(),
        'db_error_message' => $db->lastErrorMsg(),
    ], 'pre-fix');
    // #endregion
}

function plugin_station_list_local_orders(?int $hostUserUid = null, ?string $siteId = null): array
{
    $db = plugin_station_db();
    $items = [];
    $sql = 'SELECT trace_id, site_id, source_order_id, host_user_uid, host_username, host_display_name, host_user_uuid, account, password, school_name, platform, target_km, price_cents, status, status_message, host_charge_amount, host_charge_applied, refunded_cents, refund_count, refund_reason, last_refund_trade_no, refund_updated_at, main_order_status, current_km, main_target_km, status_query_updated_at, host_refund_amount, host_refund_count, created_at, updated_at FROM plugin_local_orders';
    $conditions = [];
    if ($siteId !== null && $siteId !== '') {
        $conditions[] = "(COALESCE(site_id, '') = :site_id OR COALESCE(site_id, '') = '')";
    }
    if ($hostUserUid !== null) {
        $conditions[] = 'host_user_uid = :host_user_uid';
    }
    $query = $sql;
    if (!empty($conditions)) {
        $query .= ' WHERE ' . implode(' AND ', $conditions);
    }
    $query .= " ORDER BY CASE WHEN COALESCE(created_at, '') = '' THEN updated_at ELSE created_at END DESC LIMIT 200";
    $stmt = $db->prepare($query);
    if ($siteId !== null && $siteId !== '') {
        $stmt->bindValue(':site_id', $siteId, SQLITE3_TEXT);
    }
    if ($hostUserUid !== null) {
        $stmt->bindValue(':host_user_uid', $hostUserUid, SQLITE3_INTEGER);
    }
    $result = $stmt->execute();
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $items[] = $row;
    }
    return $items;
}

function plugin_station_get_local_order(string $traceId, ?string $siteId = null): ?array
{
    $db = plugin_station_db();
    $query = '
        SELECT trace_id, site_id, source_order_id, host_user_uid, host_username, host_display_name, host_user_uuid,
               account, password, school_name, platform, target_km, price_cents, status, status_message,
               host_charge_amount, host_charge_applied, refunded_cents, refund_count, refund_reason,
               last_refund_trade_no, refund_updated_at, main_order_status, current_km, main_target_km, status_query_updated_at,
               host_refund_amount, host_refund_count, created_at, updated_at
        FROM plugin_local_orders
        WHERE trace_id = :trace_id
    ';
    if ($siteId !== null && $siteId !== '') {
        $query .= " AND (COALESCE(site_id, '') = :site_id OR COALESCE(site_id, '') = '')";
    }
    $stmt = $db->prepare($query);
    $stmt->bindValue(':trace_id', $traceId, SQLITE3_TEXT);
    if ($siteId !== null && $siteId !== '') {
        $stmt->bindValue(':site_id', $siteId, SQLITE3_TEXT);
    }
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return $row ?: null;
}

function plugin_station_get_accessible_order(string $traceId, array $currentUser): ?array
{
    $order = plugin_station_get_local_order($traceId, plugin_station_current_site_id());
    if (!$order) {
        return null;
    }
    return plugin_station_is_order_visible_in_scope($order, $currentUser, plugin_station_current_site_id()) ? $order : null;
}

function plugin_station_visible_orders(array $currentUser): array
{
    $siteId = plugin_station_current_site_id();
    if (plugin_station_is_admin_user($currentUser)) {
        return plugin_station_list_local_orders(null, $siteId);
    }
    return plugin_station_list_local_orders((int)($currentUser['uid'] ?? 0), $siteId);
}

function plugin_station_meta_cache_ttl(string $cacheKey): int
{
    if ($cacheKey === 'prices') {
        return 60;
    }
    return 60;
}

function plugin_station_get_meta_cache(string $cacheKey): ?array
{
    $db = plugin_station_db();
    $stmt = $db->prepare('SELECT payload_json, fetched_at FROM plugin_meta_cache WHERE cache_key = :cache_key LIMIT 1');
    $stmt->bindValue(':cache_key', $cacheKey, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
    if (!$row) {
        return null;
    }
    $payload = json_decode((string)($row['payload_json'] ?? ''), true);
    if (!is_array($payload)) {
        return null;
    }
    return [
        'payload' => $payload,
        'fetched_at' => (int)($row['fetched_at'] ?? 0),
    ];
}

function plugin_station_save_meta_cache(string $cacheKey, array $payload): void
{
    $db = plugin_station_db();
    $stmt = $db->prepare('
        INSERT INTO plugin_meta_cache (cache_key, payload_json, fetched_at, updated_at)
        VALUES (:cache_key, :payload_json, :fetched_at, :updated_at)
        ON CONFLICT(cache_key) DO UPDATE SET
            payload_json = excluded.payload_json,
            fetched_at = excluded.fetched_at,
            updated_at = excluded.updated_at
    ');
    $stmt->bindValue(':cache_key', $cacheKey, SQLITE3_TEXT);
    $stmt->bindValue(':payload_json', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), SQLITE3_TEXT);
    $stmt->bindValue(':fetched_at', time(), SQLITE3_INTEGER);
    $stmt->bindValue(':updated_at', plugin_station_now(), SQLITE3_TEXT);
    $stmt->execute();
}

function plugin_station_delete_meta_cache(string $cacheKey): void
{
    $db = plugin_station_db();
    $stmt = $db->prepare('DELETE FROM plugin_meta_cache WHERE cache_key = :cache_key');
    $stmt->bindValue(':cache_key', $cacheKey, SQLITE3_TEXT);
    $stmt->execute();
}

function plugin_station_ticket_cache_key(array $currentUser): string
{
    return 'tickets_user_' . (int)($currentUser['uid'] ?? 0);
}

function plugin_station_ticket_cache_ttl(): int
{
    return 3;
}

function plugin_station_clear_ticket_cache(array $currentUser): void
{
    $uid = (int)($currentUser['uid'] ?? 0);
    if ($uid <= 0) {
        return;
    }
    plugin_station_delete_meta_cache(plugin_station_ticket_cache_key($currentUser));
}

function plugin_station_cached_ticket_list_request(array $currentUser): array
{
    $uid = (int)($currentUser['uid'] ?? 0);
    if ($uid <= 0) {
        return [
            'success' => false,
            'code' => 401,
            'message' => '请先登录站点账号',
        ];
    }

    $cacheKey = plugin_station_ticket_cache_key($currentUser);
    $cached = plugin_station_get_meta_cache($cacheKey);
    $ttl = plugin_station_ticket_cache_ttl();
    if ($cached && (time() - (int)$cached['fetched_at']) < $ttl) {
        return $cached['payload'];
    }

    $result = plugin_station_gateway_request('GET', '/open/plugin/tickets', array_merge([
        'site_id' => plugin_station_config()['site_id'],
    ], plugin_station_host_user_scope_payload($currentUser)));

    if (($result['success'] ?? false) && is_array($result['data'] ?? null)) {
        plugin_station_save_meta_cache($cacheKey, $result);
        return $result;
    }

    if ($cached) {
        $fallback = $cached['payload'];
        $fallback['cache_stale'] = true;
        if (empty($fallback['message'])) {
            $fallback['message'] = 'ok';
        }
        return $fallback;
    }

    return $result;
}

function plugin_station_cached_gateway_meta_request(string $cacheKey, string $path, array $payload, bool $forceRefresh = false): array
{
    $preferLiveGateway = in_array($cacheKey, ['schools', 'platforms', 'prices'], true);
    $cached = plugin_station_get_meta_cache($cacheKey);
    $ttl = plugin_station_meta_cache_ttl($cacheKey);
    $isFresh = $cached && (time() - (int)$cached['fetched_at']) < $ttl;
    if (!$forceRefresh && !$preferLiveGateway && $isFresh && $cached) {
        $result = $cached['payload'];
        return $result;
    }

    $result = plugin_station_gateway_request('GET', $path, $payload);
    if (($result['success'] ?? false) && isset($result['data'])) {
        plugin_station_save_meta_cache($cacheKey, $result);
        return $result;
    }

    if ($cached) {
        $fallback = $cached['payload'];
        $fallback['cache_stale'] = true;
        if (empty($fallback['message'])) {
            $fallback['message'] = 'ok';
        }
        return $fallback;
    }

    return $result;
}

function plugin_station_refresh_meta_catalog_cache(): array
{
    $config = plugin_station_config();
    return [
        'schools' => plugin_station_cached_gateway_meta_request('schools', '/open/plugin/meta/schools', [
            'site_id' => $config['site_id'],
        ], true),
        'platforms' => plugin_station_cached_gateway_meta_request('platforms', '/open/plugin/meta/platforms', [
            'site_id' => $config['site_id'],
        ], true),
        'prices' => plugin_station_cached_gateway_meta_request('prices', '/open/plugin/meta/prices', [
            'site_id' => $config['site_id'],
        ], true),
    ];
}

function plugin_station_sync_recent_pending_orders(int $limit = 6): void
{
    $items = plugin_station_list_local_orders(null, plugin_station_current_site_id());
    $synced = 0;
    foreach ($items as $item) {
        if (!in_array((string)($item['status'] ?? ''), ['pending_delivery', 'pending_review'], true)) {
            continue;
        }
        plugin_station_sync_remote_status((string)($item['trace_id'] ?? ''));
        $synced++;
        if ($synced >= $limit) {
            break;
        }
    }
}

function plugin_station_apply_host_user_charge(array $currentUser, int $priceCents, string $traceId): array
{
    if ($priceCents <= 0) {
        return ['success' => true, 'amount' => 0.0];
    }

    $db = plugin_station_host_db();
    if (!$db) {
        return ['success' => false, 'message' => '宿主站数据库未就绪'];
    }

    $uid = (int)($currentUser['uid'] ?? 0);
    if ($uid <= 0) {
        return ['success' => false, 'message' => '当前用户不存在'];
    }

    $amount = plugin_station_money_from_cents($priceCents);
    $amountSql = plugin_station_money_sql($amount);
    $db->query("UPDATE qingka_wangke_user SET money=money-'{$amountSql}' WHERE uid='{$uid}' AND money>='{$amountSql}' LIMIT 1");
    if (method_exists($db, 'affected') && (int)$db->affected() < 1) {
        return ['success' => false, 'message' => '站点用户余额不足'];
    }

    if (function_exists('wlog')) {
        wlog($uid, '插件下单', '插件订单 ' . $traceId . ' 扣款', -1 * $amount);
    }

    return ['success' => true, 'amount' => $amount];
}

function plugin_station_release_host_user_charge(array $currentUser, int $priceCents, string $traceId, string $reason = ''): array
{
    if ($priceCents <= 0) {
        return ['success' => true, 'amount' => 0.0];
    }

    $db = plugin_station_host_db();
    if (!$db) {
        return ['success' => false, 'message' => '宿主站数据库未就绪'];
    }

    $uid = (int)($currentUser['uid'] ?? 0);
    if ($uid <= 0) {
        return ['success' => false, 'message' => '当前用户不存在'];
    }

    $amount = plugin_station_money_from_cents($priceCents);
    $amountSql = plugin_station_money_sql($amount);
    $db->query("UPDATE qingka_wangke_user SET money=money+'{$amountSql}' WHERE uid='{$uid}' LIMIT 1");
    if (method_exists($db, 'affected') && (int)$db->affected() < 1) {
        return ['success' => false, 'message' => '站点用户余额回滚失败'];
    }

    if (function_exists('wlog')) {
        $detail = '插件订单 ' . $traceId . ' 回滚扣款';
        if ($reason !== '') {
            $detail .= '（' . $reason . '）';
        }
        wlog($uid, '插件下单回滚', $detail, $amount);
    }

    return ['success' => true, 'amount' => $amount];
}

function plugin_station_apply_host_user_refund(array $order, int $refundCents, string $reason = ''): array
{
    if ($refundCents <= 0) {
        return [
            'success' => true,
            'amount' => 0.0,
            'total_refund_amount' => (float)($order['host_refund_amount'] ?? 0),
            'total_refund_count' => (int)($order['host_refund_count'] ?? 0),
        ];
    }

    $db = plugin_station_host_db();
    if (!$db) {
        return ['success' => false, 'message' => '宿主站数据库未就绪'];
    }

    $uid = (int)($order['host_user_uid'] ?? 0);
    if ($uid <= 0) {
        return ['success' => false, 'message' => '当前订单缺少站点用户'];
    }
    if ((int)($order['host_charge_applied'] ?? 0) !== 1) {
        return ['success' => false, 'message' => '当前订单未记录有效扣款，不能退款'];
    }

    $chargedCents = (int)round((float)($order['host_charge_amount'] ?? 0) * 100);
    $refundedHostCents = (int)round((float)($order['host_refund_amount'] ?? 0) * 100);
    if ($chargedCents <= 0) {
        return ['success' => false, 'message' => '当前订单扣款金额无效，不能退款'];
    }
    if ($refundCents > $chargedCents) {
        return ['success' => false, 'message' => '远端退款金额超过站内原扣款金额'];
    }

    $deltaCents = $refundCents - $refundedHostCents;
    if ($deltaCents <= 0) {
        return [
            'success' => true,
            'amount' => 0.0,
            'total_refund_amount' => plugin_station_money_from_cents($refundedHostCents),
            'total_refund_count' => (int)($order['host_refund_count'] ?? 0),
        ];
    }
    if ($deltaCents > ($chargedCents - $refundedHostCents)) {
        return ['success' => false, 'message' => '本次退款金额超过站内可退余额'];
    }

    $amount = plugin_station_money_from_cents($deltaCents);
    $amountSql = plugin_station_money_sql($amount);
    $db->query("UPDATE qingka_wangke_user SET money=money+'{$amountSql}' WHERE uid='{$uid}' LIMIT 1");
    if (method_exists($db, 'affected') && (int)$db->affected() < 1) {
        return ['success' => false, 'message' => '站点用户退款入账失败'];
    }

    if (function_exists('wlog')) {
        $detail = '插件订单 ' . (string)($order['trace_id'] ?? '') . ' 退款';
        if ($reason !== '') {
            $detail .= '（' . $reason . '）';
        }
        wlog($uid, '插件订单退款', $detail, $amount);
    }

    return [
        'success' => true,
        'amount' => $amount,
        'total_refund_amount' => plugin_station_money_from_cents($refundedHostCents + $deltaCents),
        'total_refund_count' => ((int)($order['host_refund_count'] ?? 0)) + 1,
    ];
}

function plugin_station_require_cron_access(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    $config = plugin_station_config();
    $token = (string)($config['cron_token'] ?? '');
    $provided = (string)($_GET['cron_token'] ?? $_POST['cron_token'] ?? '');
    if ($token !== '' && hash_equals($token, $provided)) {
        return;
    }

    plugin_station_json([
        'success' => false,
        'code' => 403,
        'message' => 'cron 访问未授权',
    ], 403);
}

function plugin_station_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    $options = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $options |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $json = json_encode($payload, $options);
    if ($json === false) {
        http_response_code(500);
        $fallback = json_encode([
            'success' => false,
            'code' => 500,
            'message' => 'JSON 编码失败',
        ], $options);
        echo $fallback !== false ? $fallback : '{"success":false,"code":500,"message":"JSON encode failed"}';
        exit;
    }
    echo $json;
    exit;
}
