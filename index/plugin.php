<?php

require_once dirname(__DIR__) . '/lib/bootstrap.php';

$config = plugin_station_raw_config();
$manifestPath = dirname(__DIR__) . '/public/app/.vite/manifest.json';
$entryJs = '';
$entryCss = [];
$entryJsVersion = '';
$entryCssVersions = [];

if (is_file($manifestPath)) {
    $manifest = json_decode(file_get_contents($manifestPath), true);
    if (is_array($manifest)) {
        foreach ($manifest as $item) {
            if (!empty($item['isEntry'])) {
                $entryJs = '../public/app/' . $item['file'];
                $entryJsFilePath = dirname(__DIR__) . '/public/app/' . $item['file'];
                if (is_file($entryJsFilePath)) {
                    $entryJsVersion = (string) filemtime($entryJsFilePath);
                }
                if (!empty($item['css']) && is_array($item['css'])) {
                    foreach ($item['css'] as $cssFile) {
                        $entryCss[] = '../public/app/' . $cssFile;
                        $cssFilePath = dirname(__DIR__) . '/public/app/' . $cssFile;
                        $entryCssVersions[] = is_file($cssFilePath) ? (string) filemtime($cssFilePath) : '';
                    }
                }
                break;
            }
        }
    }
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars((string) ($config['plugin_name'] ?? '对接站插件'), ENT_QUOTES, 'UTF-8'); ?></title>
    <?php foreach ($entryCss as $index => $cssFile): ?>
        <link rel="stylesheet" href="<?php echo htmlspecialchars($cssFile . (!empty($entryCssVersions[$index]) ? '?v=' . $entryCssVersions[$index] : ''), ENT_QUOTES, 'UTF-8'); ?>">
    <?php endforeach; ?>
</head>
<body>
<script>
    window.__PLUGIN_STATION__ = {
        apiBase: '../api.php',
        pluginName: <?php echo json_encode((string) ($config['plugin_name'] ?? '对接站插件'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
    };
</script>
<div id="app"></div>
<?php if ($entryJs !== ''): ?>
    <script type="module" src="<?php echo htmlspecialchars($entryJs . ($entryJsVersion !== '' ? '?v=' . $entryJsVersion : ''), ENT_QUOTES, 'UTF-8'); ?>"></script>
<?php else: ?>
    <p style="padding:24px;color:#fff;background:#111;">插件前端资源尚未构建，请先构建并上传 public/app 目录。</p>
<?php endif; ?>
</body>
</html>
