<?php

// [ 应用入口文件 ]
namespace think;

// 检测PHP环境
if (version_compare(PHP_VERSION, '7.1.0', '<')) die('require PHP > 7.1.0 !');

// Best-effort runtime directory self-heal for file cache/session/log writes.
$runtimeRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'runtime';
if (!is_link($runtimeRoot) || is_dir($runtimeRoot)) {
    foreach (['', 'cache', 'log', 'temp', 'schema'] as $subdir) {
        $path = $subdir === '' ? $runtimeRoot : $runtimeRoot . DIRECTORY_SEPARATOR . $subdir;
        if (!is_dir($path)) {
            @mkdir($path, 0775, true);
        }
    }
    // ThinkPHP file cache uses runtime/cache/<2-hex>/<hash>.php. Precreate all
    // first-level hash buckets to avoid sporadic "No such file or directory"
    // and permission drift during concurrent cache writes.
    $cacheRoot = $runtimeRoot . DIRECTORY_SEPARATOR . 'cache';
    for ($i = 0; $i < 256; $i++) {
        $bucket = $cacheRoot . DIRECTORY_SEPARATOR . str_pad(dechex($i), 2, '0', STR_PAD_LEFT);
        if (!is_dir($bucket)) {
            @mkdir($bucket, 0775, true);
        }
    }
} else {
    error_log(sprintf('runtime symlink is broken: %s', $runtimeRoot));
}

// 加载核心文件
require __DIR__ . '/../vendor/autoload.php';

// 执行HTTP应用并响应
$http = (new App())->http;

$response = $http->run();

$response->send();

$http->end($response);
