<?php
/**
 * 简单的 PSR-4 自动加载器
 *
 * 将 ShortURL\Core 命名空间映射到 core/src/ 目录
 */

spl_autoload_register(function ($class) {
    $prefix = 'ShortURL\\Core\\';
    $base_dir = __DIR__ . '/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// 加载公共函数
require_once __DIR__ . '/Helpers.php';
