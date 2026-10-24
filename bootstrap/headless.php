<?php
/**
 * Headless 入口引导文件
 *
 * 职责：提取凭证 + 调用 Auth + 初始化服务
 * 不做：KEY 验证、业务逻辑
 */

namespace ShortURL\ModHeadless;

// 加载核心层
require_once __DIR__ . '/../core/src/autoload.php';

use ShortURL\Core\Auth;
use ShortURL\Core\KeyStore;
use ShortURL\Core\JsonStore;
use ShortURL\Core\InternalApi;
use ShortURL\Core\CreateLinkService;
use ShortURL\Core\DeleteLinkService;
use ShortURL\Core\LinkQueryService;
use function ShortURL\Core\checkFileAccess;
use function ShortURL\Core\allowedKeyTypes;

// 设置响应头
header('Content-Type: application/json; charset=utf-8');
set_exception_handler(function (\Throwable $e): void {
    if (isset($GLOBALS['auth'], $GLOBALS['authContext'])) {
        $GLOBALS['auth']->complete($GLOBALS['authContext'], false);
    }
    error_log('[headless] 未处理异常: ' . $e->getMessage());
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    http_response_code(500);
    echo json_encode(['error' => 'internal_error', 'message' => '服务器内部错误'], JSON_UNESCAPED_UNICODE);
    exit;
});

// 加载配置
$cfg = require __DIR__ . '/../core/config.php';

// 处理 OPTIONS 预检（headless 为服务间调用，无需 CORS，返回 405 显式拒绝浏览器预检）
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit;
}

// 检查数据文件可读写性
$checkFiles = [
    $cfg['perm_path']  ?? '',
    $cfg['temp_path']  ?? '',
    $cfg['keys_path']  ?? '',
];
$failedFiles = [];
foreach ($checkFiles as $f) {
    if (!checkFileAccess($f)) {
        $failedFiles[] = basename($f);
    }
}
if (!empty($failedFiles)) {
    error_log('[headless_bootstrap] 数据文件不可访问: ' . implode(', ', $failedFiles));
    hl_error('data_inaccessible', '数据文件不可访问，请检查文件权限', 430);
}

// domain 占位符检测
if (strpos($cfg['domain'] ?? '', '{') !== false) {
    hl_error('config_error', 'config.php 的 domain 尚未配置', 500);
}

// 缓存 php://input
$GLOBALS['RAW_INPUT'] = @file_get_contents('php://input');
if ($GLOBALS['RAW_INPUT'] === false) {
    $GLOBALS['RAW_INPUT'] = '';
}

/**
 * 无头链路统一错误输出
 */
function hl_error(string $code, string $message, int $httpStatus): void
{
    http_response_code($httpStatus);
    echo json_encode(['error' => $code, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

// 提取凭证
function extractCredential(): ?string
{
    $token = $_SERVER['HTTP_X_HEADLESS_TOKEN'] ?? '';
    return $token !== '' ? $token : null;
}

// 初始化服务
$keyStore = new KeyStore(
    $cfg['keys_path'],
    $cfg['tz_offset'],
    $cfg['key_ttl_days'],
    $cfg['onetime_pool_size']
);
$auth = new Auth($keyStore);
$permStore = new JsonStore($cfg['perm_path'], $cfg['tz_offset']);
$tempStore = new JsonStore($cfg['temp_path'], $cfg['tz_offset']);
$internalApi = new InternalApi($cfg);
$createService = new CreateLinkService($cfg, $permStore, $tempStore, $internalApi);
$deleteService = new DeleteLinkService($permStore, $tempStore, $internalApi, $cfg);
$queryService = new LinkQueryService($cfg, $permStore, $tempStore, $internalApi);

// 认证
$credential = extractCredential();
$requestPath = rtrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? '';
$endpointMethods = [
    '/headless/api/create' => 'POST',
    '/headless/api/delete' => 'POST',
    '/headless/api/list' => 'GET',
    '/headless/api/stat' => 'GET',
];
$expectedMethod = $endpointMethods[$requestPath] ?? null;
if ($expectedMethod === null && preg_match('#^/headless/api/get/[0-9a-zA-Z-]{1,5}$#', $requestPath)) {
    $expectedMethod = 'GET';
}
if ($expectedMethod === null) {
    hl_error('not_found', '未知的端点', 404);
}
if ($requestMethod !== $expectedMethod) {
    header('Allow: ' . $expectedMethod);
    hl_error('method_not_allowed', '请求方法不允许', 405);
}
$pathParts = explode('/', trim($requestPath, '/'));
$action = match (true) {
    $requestPath === '/headless/api/create' => 'create',
    $requestPath === '/headless/api/delete' => 'delete',
    $requestPath === '/headless/api/list'   => 'list',
    $requestPath === '/headless/api/stat'   => 'stat',
    default => 'get',
};
$allowedTypes = allowedKeyTypes('headless', $action);
$authResult = $auth->authenticate($credential ?? '', $allowedTypes);

if (!$authResult->success) {
    if ($authResult->reason === 'backend_error') {
        hl_error('auth_unavailable', '认证服务暂不可用', 503);
    } elseif ($authResult->reason === 'missing') {
        $hint = '';
        if (!empty($_SERVER['HTTP_X_TOKEN'])) {
            $hint = '（检测到 X-Token 头，请改用 X-Headless-Token）';
        } elseif (!empty($GLOBALS['RAW_INPUT']) && strpos($GLOBALS['RAW_INPUT'], '"key"') !== false) {
            $hint = '（检测到 body 中的 key 字段，请改用 X-Headless-Token 头）';
        }
        hl_error('missing_key', '缺少密钥' . $hint, 401);
    } elseif ($authResult->reason === 'wrong_channel') {
        hl_error('forbidden_key_type', '该密钥类型不可用于此端点', 403);
    } else {
        hl_error('invalid_key', '密钥失效或不正确', 401);
    }
}

// 认证成功，保存 AuthContext
$authContext = $authResult->authContext;
$GLOBALS['auth'] = $auth;
$GLOBALS['authContext'] = $authContext;

function completeAuthentication(bool $success): void
{
    global $auth, $authContext;
    if (!$auth->complete($authContext, $success)) {
        throw new \RuntimeException('无法完成一次性密钥状态更新');
    }
}
