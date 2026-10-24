<?php
/**
 * WebUI 入口引导文件
 *
 * 由 api.php 单入口在路由/方法校验之后 require。
 * 职责：预检 + 提取凭证 + 调用 Auth + 初始化服务
 * 不做：KEY 验证、业务逻辑、路由分发
 */

namespace ShortURL\ModWebUI;

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
    error_log('[webui] 未处理异常: ' . $e->getMessage());
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    http_response_code(500);
    echo json_encode(['error' => '服务器内部错误', 'code' => 'internal_error'], JSON_UNESCAPED_UNICODE);
    exit;
});

// 加载配置
$cfg = require __DIR__ . '/../core/config.php';

// 前端面板总开关
if (empty($cfg['panel_enabled'])) {
    http_response_code(403);
    echo json_encode(['error' => '前端面板未启用', 'code' => 'panel_disabled'], JSON_UNESCAPED_UNICODE);
    exit;
}

// CORS 设置
$allowOrigin = $cfg['panel_origin'] ?? '';
if ($allowOrigin === '') {
    $allowOrigin = $cfg['domain'] ?? '';
}
if ($allowOrigin !== '' && $allowOrigin !== '*') {
    header('Access-Control-Allow-Origin: ' . $allowOrigin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Headers: Content-Type, X-Token');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
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
    error_log('[webui_bootstrap] 数据文件不可访问: ' . implode(', ', $failedFiles));
    http_response_code(430);
    echo json_encode(['error' => '数据文件不可访问，请检查文件权限', 'code' => 'data_inaccessible'], JSON_UNESCAPED_UNICODE);
    exit;
}

// domain 占位符检测
if (strpos($cfg['domain'] ?? '', '{') !== false) {
    http_response_code(500);
    echo json_encode(['error' => 'config.php 的 domain 尚未配置', 'code' => 'config_error'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 缓存 php://input
$GLOBALS['RAW_INPUT'] = @file_get_contents('php://input');
if ($GLOBALS['RAW_INPUT'] === false) {
    $GLOBALS['RAW_INPUT'] = '';
}

// 提取凭证
function extractCredential(): ?string
{
    $xToken = $_SERVER['HTTP_X_TOKEN'] ?? '';
    if ($xToken !== '') {
        return $xToken;
    }
    $rawInput = $GLOBALS['RAW_INPUT'] ?? '';
    if ($rawInput !== null && $rawInput !== '') {
        $json = json_decode($rawInput, true);
        if (is_array($json) && isset($json['key']) && is_string($json['key']) && $json['key'] !== '') {
            return $json['key'];
        }
    }
    return null;
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

// 认证（$action 由单入口 api.php 在路由校验后提供）
$credential = extractCredential();
$allowedTypes = allowedKeyTypes('webui', $action);
$authResult = $auth->authenticate($credential ?? '', $allowedTypes);

if (!$authResult->success) {
    if ($authResult->reason === 'backend_error') {
        http_response_code(503);
        echo json_encode(['error' => '认证服务暂不可用', 'code' => 'auth_unavailable'], JSON_UNESCAPED_UNICODE);
    } elseif ($authResult->reason === 'missing') {
        http_response_code(401);
        echo json_encode(['error' => '缺少密钥', 'code' => 'missing_key'], JSON_UNESCAPED_UNICODE);
    } elseif ($authResult->reason === 'wrong_channel') {
        http_response_code(403);
        echo json_encode(['error' => '该密钥类型不可用于此端点', 'code' => 'wrong_channel'], JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(401);
        echo json_encode(['error' => '密钥失效或不正确', 'code' => 'invalid_key'], JSON_UNESCAPED_UNICODE);
    }
    exit;
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
