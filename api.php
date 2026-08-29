<?php

require_once __DIR__ . '/lib/client.php';

$action = $_GET['action'] ?? 'bootstrap';
$config = plugin_station_config();
$user = plugin_station_resolve_current_user();
$siteInfo = plugin_station_resolve_site_info();

function plugin_station_host_profile_payload(array $currentUser): array
{
    return [
        'uid' => (int)($currentUser['uid'] ?? 0),
        'uuid' => (string)($currentUser['uuid'] ?? ''),
        'username' => (string)($currentUser['username'] ?? ''),
        'display_name' => (string)($currentUser['display_name'] ?? ''),
        'money' => (float)($currentUser['money'] ?? 0),
        'active' => (string)($currentUser['active'] ?? ''),
    ];
}

function plugin_station_host_user_scope_payload(array $currentUser): array
{
    return [
        'current_user_uid' => (int)($currentUser['uid'] ?? 0),
        'current_user_uuid' => (string)($currentUser['uuid'] ?? ''),
        'current_user_username' => (string)($currentUser['username'] ?? ''),
        'current_user_display_name' => (string)($currentUser['display_name'] ?? ''),
    ];
}

function plugin_station_estimate_price_cents(array $payload): array
{
    $priceResult = plugin_station_cached_gateway_meta_request('prices', '/open/plugin/meta/prices', [
        'site_id' => plugin_station_config()['site_id'],
    ]);
    if (!($priceResult['success'] ?? false)) {
        return [
            'success' => false,
            'message' => $priceResult['message'] ?? '价格获取失败',
        ];
    }

    $prices = $priceResult['data']['prices'] ?? [];
    $schoolName = (string)($payload['school_name'] ?? '');
    $targetKm = (float)($payload['target_km'] ?? 0);
    $isMorningRun = !empty($payload['is_morning_run']);
    foreach ($prices as $item) {
        if ((string)($item['school_name'] ?? '') !== $schoolName) {
            continue;
        }

        $unitPrice = $isMorningRun
            ? (float)($item['morning_unit_price'] ?? 0)
            : (float)($item['daily_unit_price'] ?? 0);

        return [
            'success' => true,
            'price_cents' => (int)round($unitPrice * $targetKm * 100),
        ];
    }

    return [
        'success' => false,
        'message' => '学校价格不存在',
    ];
}

function plugin_station_generate_source_order_id(array $currentUser): string
{
    $uid = (int)($currentUser['uid'] ?? 0);
    $suffix = strtoupper(substr(md5(uniqid((string)$uid, true)), 0, 8));
    return 'LOCAL_' . date('YmdHis') . '_' . $uid . '_' . $suffix;
}

function plugin_station_sync_remote_status(string $traceId): array
{
    $localOrder = plugin_station_get_local_order($traceId);
    $result = plugin_station_gateway_request('GET', '/open/plugin/orders/status', [
        'site_id' => plugin_station_config()['site_id'],
        'trace_id' => $traceId,
    ]);

    if (!($result['success'] ?? false)) {
        return $result;
    }

    if (!empty($result['data']['order'])) {
        plugin_station_save_order(array_merge($localOrder ?: [], $result['data']['order']));
        $localSaved = plugin_station_get_local_order($traceId);
        if ($localSaved) {
            $localSaved = plugin_station_settle_local_order_finance($localSaved);
            $result['data']['order'] = $localSaved;
        }
    }

    return $result;
}

switch ($action) {
    case 'bootstrap':
        if (!$siteInfo['is_logged_in']) {
            plugin_station_json([
                'success' => false,
                'code' => 401,
                'message' => '请先登录站点账号',
            ], 401);
        }
        $ping = plugin_station_gateway_request('POST', '/open/plugin/auth/ping', [
            'site_id' => $config['site_id'],
        ]);
        if (!($ping['success'] ?? false)) {
            plugin_station_json($ping, 500);
        }

        plugin_station_json([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'site_id' => $config['site_id'],
                'site_name' => $siteInfo['site_name'],
                'app_key' => $config['app_key'],
                'server_time' => date('Y-m-d H:i:s'),
                'current_user' => $user,
                'site' => $siteInfo,
            ]
        ]);
        break;

    case 'schools':
        plugin_station_require_logged_in_user();
        plugin_station_json(plugin_station_cached_gateway_meta_request('schools', '/open/plugin/meta/schools', [
            'site_id' => $config['site_id'],
        ]));
        break;

    case 'platforms':
        plugin_station_require_logged_in_user();
        plugin_station_json(plugin_station_cached_gateway_meta_request('platforms', '/open/plugin/meta/platforms', [
            'site_id' => $config['site_id'],
        ]));
        break;

    case 'prices':
        plugin_station_require_logged_in_user();
        plugin_station_json(plugin_station_cached_gateway_meta_request('prices', '/open/plugin/meta/prices', [
            'site_id' => $config['site_id'],
        ]));
        break;

    case 'host_balance':
        $currentUser = plugin_station_require_logged_in_user();
        plugin_station_json([
            'success' => true,
            'message' => 'ok',
            'data' => plugin_station_host_profile_payload($currentUser),
        ]);
        break;

    case 'host_profile':
        $currentUser = plugin_station_require_logged_in_user();
        plugin_station_json([
            'success' => true,
            'message' => 'ok',
            'data' => plugin_station_host_profile_payload($currentUser),
        ]);
        break;

    case 'orders':
        $currentUser = plugin_station_require_logged_in_user();
        plugin_station_json([
            'success' => true,
            'message' => 'ok',
            'data' => plugin_station_visible_orders($currentUser),
        ]);
        break;

    case 'tickets':
        $currentUser = plugin_station_require_logged_in_user();
        $startedAt = microtime(true);
        // #region debug-point A:tickets-entry
        plugin_station_debug_report('A', 'plugin-station/api.php:tickets:start', '[DEBUG] plugin tickets action start', [
            'site_id' => (string)$config['site_id'],
            'current_user_uid' => (int)($currentUser['uid'] ?? 0),
            'current_user_username' => (string)($currentUser['username'] ?? ''),
        ]);
        // #endregion
        $result = plugin_station_cached_ticket_list_request($currentUser);
        // #region debug-point A:tickets-finish
        plugin_station_debug_report('A', 'plugin-station/api.php:tickets:finish', '[DEBUG] plugin tickets action finish', [
            'site_id' => (string)$config['site_id'],
            'current_user_uid' => (int)($currentUser['uid'] ?? 0),
            'duration_ms' => (int)round((microtime(true) - $startedAt) * 1000),
            'success' => (bool)($result['success'] ?? false),
            'message' => (string)($result['message'] ?? ''),
            'items_count' => is_array($result['data'] ?? null) ? count($result['data']) : -1,
            'cache_stale' => (bool)($result['cache_stale'] ?? false),
        ]);
        // #endregion
        plugin_station_json($result);
        break;

    case 'ticket_detail':
        $currentUser = plugin_station_require_logged_in_user();
        $ticketId = (string)($_GET['ticket_id'] ?? '');
        if ($ticketId === '') {
            plugin_station_json(['success' => false, 'message' => 'ticket_id 不能为空'], 400);
        }
        $result = plugin_station_gateway_request('GET', '/open/plugin/tickets/detail', array_merge([
            'site_id' => $config['site_id'],
            'ticket_id' => $ticketId,
        ], plugin_station_host_user_scope_payload($currentUser)));
        plugin_station_json($result, ($result['success'] ?? false) ? 200 : 404);
        break;

    case 'create_order':
        $payload = json_decode(file_get_contents('php://input'), true) ?: [];
        $currentUser = plugin_station_require_logged_in_user();
        $sourceOrderId = plugin_station_generate_source_order_id($currentUser);
        $priceEstimate = plugin_station_estimate_price_cents($payload);
        if (!($priceEstimate['success'] ?? false)) {
            plugin_station_json($priceEstimate, 400);
        }
        $priceCents = (int)($priceEstimate['price_cents'] ?? 0);
        $chargeResult = plugin_station_apply_host_user_charge($currentUser, $priceCents, $sourceOrderId);
        if (!($chargeResult['success'] ?? false)) {
            plugin_station_json($chargeResult, 400);
        }
        $result = plugin_station_gateway_request('POST', '/open/plugin/orders/create', [
            'site_id' => $config['site_id'],
            'source_order_id' => $sourceOrderId,
            'current_user' => $currentUser,
            'account' => $payload['account'] ?? '',
            'password' => $payload['password'] ?? '',
            'school_name' => $payload['school_name'] ?? '',
            'platform' => $payload['platform'] ?? '',
            'target_km' => (float) ($payload['target_km'] ?? 0),
            'run_mode_enabled' => !empty($payload['run_mode_enabled']),
            'is_morning_run' => !empty($payload['is_morning_run']),
            'run_mode_text' => $payload['run_mode_text'] ?? '',
        ]);

        if (!($result['success'] ?? false)) {
            plugin_station_apply_host_user_refund([
                'trace_id' => $sourceOrderId,
                'host_user_uid' => (int)($currentUser['uid'] ?? 0),
                'price_cents' => $priceCents,
                'host_charge_amount' => (float)($chargeResult['amount'] ?? 0),
                'host_charge_applied' => 1,
                'host_refund_applied' => 0,
            ], '插件订单创建失败，退回站点用户余额');
            plugin_station_json($result, 400);
        }

        $gatewayData = is_array($result['data'] ?? null) ? $result['data'] : [];
        $traceId = (string)($gatewayData['trace_id'] ?? $result['trace_id'] ?? '');
        if ($traceId === '') {
            plugin_station_apply_host_user_refund([
                'trace_id' => $sourceOrderId,
                'host_user_uid' => (int)($currentUser['uid'] ?? 0),
                'price_cents' => $priceCents,
                'host_charge_amount' => (float)($chargeResult['amount'] ?? 0),
                'host_charge_applied' => 1,
                'host_refund_applied' => 0,
            ], '插件订单创建未返回 trace_id，退回站点用户余额');
            plugin_station_json([
                'success' => false,
                'code' => 500,
                'message' => '插件网关未返回 trace_id',
            ], 500);
        }

        $remoteStatus = (string)($gatewayData['order_status'] ?? $gatewayData['bridge_status']['data']['order_status'] ?? 'pending_delivery');
        $remoteMessage = (string)($gatewayData['message'] ?? $result['message'] ?? '提交成功，待送达主站');

        plugin_station_save_order([
            'trace_id' => $traceId,
            'source_order_id' => $sourceOrderId,
            'host_user_uid' => (int)($currentUser['uid'] ?? 0),
            'host_username' => (string)($currentUser['username'] ?? ''),
            'host_display_name' => (string)($currentUser['display_name'] ?? ''),
            'host_user_uuid' => (string)($currentUser['uuid'] ?? ''),
            'account' => $payload['account'] ?? '',
            'school_name' => $payload['school_name'] ?? '',
            'platform' => $payload['platform'] ?? '',
            'target_km' => (float) ($payload['target_km'] ?? 0),
            'price_cents' => (int)($gatewayData['price_cents'] ?? $result['price_cents'] ?? $priceCents),
            'status' => $remoteStatus,
            'status_message' => $remoteMessage,
            'host_charge_amount' => (float)($chargeResult['amount'] ?? 0),
            'host_charge_applied' => 1,
            'host_refund_amount' => 0,
            'host_refund_applied' => 0,
        ]);

        plugin_station_json([
            'success' => true,
            'message' => $remoteMessage,
            'trace_id' => $traceId,
            'price_cents' => (int)($gatewayData['price_cents'] ?? $result['price_cents'] ?? $priceCents),
            'next_poll_after_ms' => (int)($gatewayData['next_poll_after_ms'] ?? $result['next_poll_after_ms'] ?? 1200),
            'data' => [
                'accepted' => true,
                'order_status' => $remoteStatus,
                'bridge_status' => $gatewayData['bridge_status'] ?? null,
            ],
        ]);
        break;

    case 'order_status':
        $currentUser = plugin_station_require_logged_in_user();
        $traceId = (string) ($_GET['trace_id'] ?? '');
        if ($traceId === '') {
            plugin_station_json(['success' => false, 'message' => 'trace_id 不能为空'], 400);
        }
        if (!plugin_station_get_accessible_order($traceId, $currentUser)) {
            plugin_station_json(['success' => false, 'message' => '订单不存在或无权查看'], 404);
        }
        plugin_station_json(plugin_station_sync_remote_status($traceId));
        break;

    case 'refund_order':
        $currentUser = plugin_station_require_logged_in_user();
        $payload = json_decode(file_get_contents('php://input'), true) ?: [];
        $traceId = (string) ($payload['trace_id'] ?? '');
        if ($traceId === '') {
            plugin_station_json(['success' => false, 'message' => 'trace_id 不能为空'], 400);
        }
        $local = plugin_station_get_accessible_order($traceId, $currentUser);
        if (!$local) {
            plugin_station_json(['success' => false, 'message' => '订单不存在或无权退款'], 404);
        }
        $result = plugin_station_gateway_request('POST', '/open/plugin/orders/refund', [
            'trace_id' => $traceId,
        ]);
        if (($result['success'] ?? false) && $traceId !== '') {
            $local['status'] = 'refund_pending';
            $local['status_message'] = $result['message'] ?? '退款申请已提交';
            plugin_station_save_order($local);
        }
        plugin_station_json($result, ($result['success'] ?? false) ? 200 : 400);
        break;

    case 'create_ticket':
        $payload = json_decode(file_get_contents('php://input'), true) ?: [];
        $currentUser = plugin_station_require_logged_in_user();
        $linkedTraceId = (string)($payload['linked_trace_id'] ?? '');
        $linkedOrder = null;
        $account = trim((string)($payload['account'] ?? ''));
        $password = trim((string)($payload['password'] ?? ''));
        $school = trim((string)($payload['school'] ?? ''));
        if ($linkedTraceId !== '') {
            $linkedOrder = plugin_station_get_accessible_order($linkedTraceId, $currentUser);
            if (!$linkedOrder) {
                plugin_station_json(['success' => false, 'message' => '关联订单不存在或无权使用'], 404);
            }
        }
        $gatewayPayload = array_merge([
            'site_id' => $config['site_id'],
            'fault_type' => (string)($payload['fault_type'] ?? ''),
            'message' => (string)($payload['message'] ?? ''),
            'account' => $account !== '' ? $account : (string)($linkedOrder['account'] ?? ''),
            'password' => $password,
            'school' => $school !== '' ? $school : (string)($linkedOrder['school_name'] ?? ''),
            'source_trace_id' => $linkedOrder['trace_id'] ?? '',
            'source_order_id' => $linkedOrder['source_order_id'] ?? '',
        ], plugin_station_host_user_scope_payload($currentUser));
        $result = plugin_station_gateway_request('POST', '/open/plugin/tickets/create', $gatewayPayload);
        if (($result['success'] ?? false)) {
            plugin_station_clear_ticket_cache($currentUser);
        }
        plugin_station_json($result, ($result['success'] ?? false) ? 200 : 400);
        break;

    case 'reply_ticket':
        $payload = json_decode(file_get_contents('php://input'), true) ?: [];
        $currentUser = plugin_station_require_logged_in_user();
        $ticketId = (string)($payload['ticket_id'] ?? '');
        if ($ticketId === '') {
            plugin_station_json(['success' => false, 'message' => 'ticket_id 不能为空'], 400);
        }
        $result = plugin_station_gateway_request('POST', '/open/plugin/tickets/reply', array_merge([
            'site_id' => $config['site_id'],
            'ticket_id' => $ticketId,
            'message' => (string)($payload['message'] ?? ''),
        ], plugin_station_host_user_scope_payload($currentUser)));
        if (($result['success'] ?? false)) {
            plugin_station_clear_ticket_cache($currentUser);
        }
        plugin_station_json($result, ($result['success'] ?? false) ? 200 : 400);
        break;

    case 'cron_sync':
        plugin_station_require_cron_access();
        plugin_station_refresh_meta_catalog_cache();
        plugin_station_sync_recent_pending_orders(30);
        plugin_station_json(['success' => true, 'message' => '同步完成']);
        break;

    default:
        plugin_station_json([
            'success' => false,
            'message' => '未知 action',
        ], 404);
}
