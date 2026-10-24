<?php
/**
 * Helpers — 公共工具函数
 *
 * 跨模块共享的工具函数。
 */

namespace ShortURL\Core;

/**
 * 将 Unix 时间戳格式化为带时区偏移的 ISO 8601 字符串
 *
 * @param int $ts Unix 时间戳
 * @param string $tz 时区偏移或时区名称
 * @return string ISO 8601 格式的日期时间字符串
 * @throws \InvalidArgumentException 时区格式无效时抛出
 */
function formatIso8601(int $ts, string $tz): string
{
    // 时区名称格式（如 'Asia/Shanghai'）
    if (preg_match('/^[A-Z]/i', $tz)) {
        try {
            $dt = new \DateTime("@$ts");
            $dt->setTimezone(new \DateTimeZone($tz));
            return $dt->format('Y-m-d\TH:i:sP');
        } catch (\Exception $e) {
            throw new \InvalidArgumentException("无效时区名称: {$tz}");
        }
    }

    // 偏移格式（如 '+08:00'）
    if (!preg_match('/^[+-]\d{2}:\d{2}$/', $tz)) {
        throw new \InvalidArgumentException("无效时区格式: {$tz}");
    }
    $sign = $tz[0];
    $parts = explode(':', substr($tz, 1));
    if (intval($parts[0]) > 23 || intval($parts[1]) > 59) {
        throw new \InvalidArgumentException("无效时区偏移: {$tz}");
    }
    $offsetSec = intval($parts[0]) * 3600 + intval($parts[1]) * 60;
    if ($sign === '-') $offsetSec = -$offsetSec;
    $localTs = $ts + $offsetSec;
    return gmdate('Y-m-d\TH:i:s', $localTs) . $tz;
}

/**
 * 判断临时条目是否已过期
 *
 * @param string|null $expStr 过期时间 ISO 8601 字符串，null 或 '0' 表示永久
 * @return bool 已过期返回 true
 */
function isExpired(?string $expStr): bool
{
    if ($expStr === null || $expStr === '0' || $expStr === '') return false;
    $ts = strtotime($expStr);
    if ($ts === false) return true;
    return $ts <= time();
}

/**
 * 从数据数组中原地移除过期条目
 *
 * @param array &$data 数据数组引用（code => entry）
 * @return int 移除的条目数
 */
function cleanExpiredEntries(array &$data): int
{
    $removed = 0;
    foreach ($data as $k => $item) {
        if (isExpired($item['t'] ?? null)) {
            unset($data[$k]);
            $removed++;
        }
    }
    return $removed;
}

/**
 * 检查单个文件的可访问性
 *
 * @param string $path 文件路径
 * @return bool 可访问返回 true
 */
function checkFileAccess(string $path): bool
{
    if ($path === '' || $path === null) return false;
    if (file_exists($path)) {
        return is_readable($path) && is_writable($path);
    }
    $dir = dirname($path);
    return is_dir($dir) && is_writable($dir);
}

/**
 * SSRF 防护：检查 URL 是否指向内网/保留地址
 *
 * @param string $url 待检查的完整 URL
 * @return bool 指向内网返回 true
 */
function isPrivateUrl(string $url): bool
{
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host) return true;

    $host = trim($host, '[]');

    $ip = filter_var($host, FILTER_VALIDATE_IP);
    if ($ip !== false) {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    $records = @dns_get_record($host, DNS_A + DNS_AAAA);
    if ($records === false || empty($records)) {
        return true;
    }

    foreach ($records as $record) {
        $ip = $record['ip'] ?? $record['ipv6'] ?? null;
        if (!$ip) continue;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }
    }

    return false;
}

/**
 * 获取端点允许的密钥类型
 *
 * @param string $source 来源模块 ('webui' 或 'headless')
 * @param string $action 操作名称 ('create', 'delete', 'list', 'stat', 'get')
 * @return array 允许的密钥类型数组
 * @throws \InvalidArgumentException 未知的 source/action 组合
 */
function allowedKeyTypes(string $source, string $action): array
{
    $strategy = [
        'webui' => [
            'create' => ['resident', 'onetime'],
            'delete' => ['resident'],
            'list'   => ['resident'],
            'stat'   => ['resident'],
        ],
        'headless' => [
            'create' => ['resident', 'onetime', 'service'],
            'delete' => ['resident', 'service'],
            'list'   => ['resident', 'service'],
            'stat'   => ['resident', 'service'],
            'get'    => ['resident', 'service'],
        ],
    ];

    if (!isset($strategy[$source])) {
        throw new \InvalidArgumentException("未知的来源模块: {$source}");
    }
    if (!isset($strategy[$source][$action])) {
        throw new \InvalidArgumentException("未知的操作: {$source}/{$action}");
    }

    return $strategy[$source][$action];
}

/**
 * SHA-256 哈希运算
 *
 * @param string $input 输入字符串
 * @return string 十六进制哈希值
 */
function auth_hash(string $input): string
{
    return hash('sha256', $input);
}

/** Base58 编码（纯 PHP 实现，无扩展依赖） */
const BASE58_ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

function base58_encode(string $bytes): string
{
    if ($bytes === '') return '';

    $len = strlen($bytes);
    $leadingZeros = 0;
    while ($leadingZeros < $len && $bytes[$leadingZeros] === "\x00") {
        $leadingZeros++;
    }

    $digits = [];
    for ($i = 0; $i < $len; $i++) {
        $digits[] = ord($bytes[$i]);
    }

    $encoded = '';
    $start = $leadingZeros;
    while ($start < count($digits)) {
        $remainder = 0;
        for ($i = $start, $count = count($digits); $i < $count; $i++) {
            $value = ($remainder * 256) + $digits[$i];
            $digits[$i] = intdiv($value, 58);
            $remainder = $value % 58;
        }
        $encoded = BASE58_ALPHABET[$remainder] . $encoded;
        while ($start < count($digits) && $digits[$start] === 0) {
            $start++;
        }
    }

    return str_repeat('1', $leadingZeros) . $encoded;
}
