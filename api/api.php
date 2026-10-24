<?php
namespace ShortURL\ModWebUI;

/**
 * api.php — WebUI API 单入口
 *
 * 路由 → 预检 → 认证 → 业务，URL 契约与 v1.11.0 保持一致：
 *   POST /api/create   POST /api/delete
 *   GET  /api/list     GET  /api/stat     GET /api/ping
 *
 * Nginx 将所有 /api/* 请求指向本文件；方法校验与端点分发在 PHP 层完成。
 */

// 解析请求路径
$path = rtrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
if ($path === '') $path = '/';

// CORS 预检：必须在方法校验之前处理，否则 OPTIONS 永远 405
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    // 加载配置以获取正确的 CORS origin
    $corsCfg = require __DIR__ . '/../core/config.php';
    $corsOrigin = $corsCfg['panel_origin'] ?? '';
    if ($corsOrigin === '') {
        $corsOrigin = $corsCfg['domain'] ?? '';
    }
    header('Content-Type: application/json; charset=utf-8');
    if ($corsOrigin !== '' && $corsOrigin !== '*') {
        header('Access-Control-Allow-Origin: ' . $corsOrigin);
        header('Vary: Origin');
    }
    header('Access-Control-Allow-Headers: Content-Type, X-Token');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    http_response_code(204);
    exit;
}

// 存活探测：无需引导、无需认证（仅允许 GET/HEAD）
if ($path === '/api/ping') {
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    header('Content-Type: application/json; charset=utf-8');
    if ($method !== 'GET' && $method !== 'HEAD') {
        header('Allow: GET, HEAD');
        http_response_code(405);
        echo json_encode(['error' => '请求方法不允许', 'code' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

$routes = [
    '/api/create' => ['create', 'POST'],
    '/api/delete' => ['delete', 'POST'],
    '/api/list'   => ['list', 'GET'],
    '/api/stat'   => ['stat', 'GET'],
];

if (!isset($routes[$path])) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(404);
    echo json_encode(['error' => '未知的端点', 'code' => 'not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}

[$action, $expectedMethod] = $routes[$path];

if (($_SERVER['REQUEST_METHOD'] ?? '') !== $expectedMethod) {
    header('Content-Type: application/json; charset=utf-8');
    header('Allow: ' . $expectedMethod);
    http_response_code(405);
    echo json_encode(['error' => '请求方法不允许', 'code' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 预检 + 认证 + 服务初始化（$action 供 allowedKeyTypes 使用）
require __DIR__ . '/../bootstrap/webui.php';

switch ($action) {
    case 'create':
        handleCreate();
        break;
    case 'delete':
        handleDelete();
        break;
    case 'list':
        handleList();
        break;
    case 'stat':
        handleStat();
        break;
}

/**
 * POST /api/create - 创建短链
 */
function handleCreate(): void
{
    global $createService, $authContext;

    $input = json_decode($GLOBALS['RAW_INPUT'] ?? '{}', true);
    if (!is_array($input)) {
        try { completeAuthentication(false); } catch (\Throwable $e) { error_log('[webui] completeAuthentication: ' . $e->getMessage()); }
        http_response_code(400);
        echo json_encode(['error' => '无效的请求体', 'code' => 'bad_request'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $result = $createService->execute($authContext, $input);

    if (!$result->ok) {
        try { completeAuthentication(false); } catch (\Throwable $e) { error_log('[webui] completeAuthentication: ' . $e->getMessage()); }
        http_response_code($result->httpStatus ?? 400);
        echo json_encode(['error' => $result->message, 'code' => $result->code], JSON_UNESCAPED_UNICODE);
        exit;
    }

    completeAuthentication(true);
    echo json_encode($result->data, JSON_UNESCAPED_UNICODE);
}

/**
 * POST /api/delete - 删除短链
 */
function handleDelete(): void
{
    global $deleteService, $authContext;

    $input = json_decode($GLOBALS['RAW_INPUT'] ?? '{}', true);
    if (!is_array($input)) {
        try { completeAuthentication(false); } catch (\Throwable $e) { error_log('[webui] completeAuthentication: ' . $e->getMessage()); }
        http_response_code(400);
        echo json_encode(['error' => '无效的请求体', 'code' => 'bad_request'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!isset($input['code']) || !is_string($input['code'])) {
        try { completeAuthentication(false); } catch (\Throwable $e) { error_log('[webui] completeAuthentication: ' . $e->getMessage()); }
        http_response_code(400);
        echo json_encode(['error' => '短链后缀必须为字符串', 'code' => 'invalid_code'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $code = trim($input['code']);
    if ($code === '') {
        try { completeAuthentication(false); } catch (\Throwable $e) { error_log('[webui] completeAuthentication: ' . $e->getMessage()); }
        http_response_code(400);
        echo json_encode(['error' => '缺少短链后缀', 'code' => 'missing_code'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!preg_match('/^[0-9a-zA-Z-]{1,5}$/', $code)) {
        try { completeAuthentication(false); } catch (\Throwable $e) { error_log('[webui] completeAuthentication: ' . $e->getMessage()); }
        http_response_code(400);
        echo json_encode(['error' => '短链后缀格式无效', 'code' => 'invalid_code'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $result = $deleteService->execute($authContext, $code);

    if (!$result->ok) {
        try { completeAuthentication(false); } catch (\Throwable $e) { error_log('[webui] completeAuthentication: ' . $e->getMessage()); }
        http_response_code($result->httpStatus ?? 400);
        echo json_encode(['error' => $result->message, 'code' => $result->code], JSON_UNESCAPED_UNICODE);
        exit;
    }

    completeAuthentication(true);
    echo json_encode($result->data, JSON_UNESCAPED_UNICODE);
}

/**
 * GET /api/list - 列出所有短链（v1 分组结构）
 */
function handleList(): void
{
    global $queryService, $authContext;

    $result = $queryService->listGrouped($authContext);

    if (!$result->ok) {
        try { completeAuthentication(false); } catch (\Throwable $e) { error_log('[webui] completeAuthentication: ' . $e->getMessage()); }
        http_response_code($result->httpStatus ?? 400);
        echo json_encode(['error' => $result->message, 'code' => $result->code], JSON_UNESCAPED_UNICODE);
        exit;
    }

    completeAuthentication(true);
    echo json_encode($result->data, JSON_UNESCAPED_UNICODE);
}

/**
 * GET /api/stat - 获取配额状态
 */
function handleStat(): void
{
    global $queryService, $authContext;

    $result = $queryService->stat($authContext);

    if (!$result->ok) {
        try { completeAuthentication(false); } catch (\Throwable $e) { error_log('[webui] completeAuthentication: ' . $e->getMessage()); }
        http_response_code($result->httpStatus ?? 400);
        echo json_encode(['error' => $result->message, 'code' => $result->code], JSON_UNESCAPED_UNICODE);
        exit;
    }

    completeAuthentication(true);
    echo json_encode($result->data, JSON_UNESCAPED_UNICODE);
}
