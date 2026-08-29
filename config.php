<?php

$defaults = [
    'plugin_name' => '二九对接站插件',
    'gateway_base_url' => 'http://127.0.0.1:9325',
    'site_id' => 'site_demo_29',
    'app_key' => 'demo_app_key_29',
    'app_secret' => 'demo_app_secret_29',
    'site_name' => '二九网课模拟插件测试站',
    'db_path' => __DIR__ . '/data/plugin-local.sqlite',
    'host_root' => '',
    'host_common_path' => '',
    'cron_token' => '',
];

$localConfig = [];
$localConfigPath = __DIR__ . '/config.local.php';
if (is_file($localConfigPath)) {
    $loaded = require $localConfigPath;
    if (is_array($loaded)) {
        $localConfig = $loaded;
    }
}

$config = array_merge($defaults, $localConfig);

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
