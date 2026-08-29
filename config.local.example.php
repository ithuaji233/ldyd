<?php

return [
    'plugin_name' => '二九运动对接插件',
    'gateway_base_url' => 'https://你的对接站域名',
    'site_id' => '替换为分配的site_id',
    'app_key' => '替换为分配的app_key',
    'app_secret' => '替换为分配的app_secret',
    'site_name' => '当前站点名称',
    'db_path' => __DIR__ . '/data/plugin-local.sqlite',

    // 插件目录不在站点根目录时，填 host_root 或 host_common_path 二选一即可。
    'host_root' => '',
    'host_common_path' => '',

    // 如需通过 URL 触发同步，可填写一个 cron token。
    'cron_token' => '请替换为随机长字符串',
];
