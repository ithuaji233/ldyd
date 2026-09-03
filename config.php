<?php

$config = [
    'plugin_name' => '牢大运动',
    'gateway_base_url' => 'https://hk-orix.ld-yd.com',
    'site_id' => 'site_demo_29',
    'app_key' => 'demo_app_key_29',
    'app_secret' => 'demo_app_secret_29',
    'site_name' => '当前站点名称',
    'db_path' => __DIR__ . '/data/plugin-local.sqlite',

    // 优先留空，插件会自动查找宿主站的 common.php。
    // 只有自动查找不到时，才手动填写其中一个。
    'host_root' => '',
    'host_common_path' => '',

    // 如需通过 URL 触发同步，可填写一个随机长 token。
    'cron_token' => '',
];

$envMap = [
    'PLUGIN_NAME' => 'plugin_name',
    'PLUGIN_GATEWAY_BASE_URL' => 'gateway_base_url',
    'PLUGIN_SITE_ID' => 'site_id',
    'PLUGIN_APP_KEY' => 'app_key',
    'PLUGIN_APP_SECRET' => 'app_secret',
    'PLUGIN_SITE_NAME' => 'site_name',
    'PLUGIN_DB_PATH' => 'db_path',
    'PLUGIN_HOST_ROOT' => 'host_root',
    'PLUGIN_HOST_COMMON_PATH' => 'host_common_path',
    'PLUGIN_CRON_TOKEN' => 'cron_token',
];

foreach ($envMap as $envName => $configKey) {
    $envValue = getenv($envName);
    if ($envValue !== false && $envValue !== '') {
        $config[$configKey] = $envValue;
    }
}

return $config;
