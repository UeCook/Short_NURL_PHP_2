<?php
namespace ShortURL\ModHeadless;

/**
 * Headless 单入口路由
 *
 * 先 parse_url 提取路径，然后路由到对应的处理函数
 */

require __DIR__ . '/../bootstrap/headless.php';

// 获取请求路径
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = rtrim((string) parse_url($requestUri, PHP_URL_PATH), '/');
if ($path === '') $path = '/';

// 路由分发
switch ($path) {
    case '/headless/api/create':
        handleCreate();
        break;
    case '/headless/api/delete':
        handleDelete();
        break;
    case '/headless/api/list':
        handleList();
        break;
    case '/headless/api/stat':
        handleStat();
        break;
    default:
        // 检查是否是 get 请求（/headless/api/get/{code}）
        if (preg_match('#^/headless/api/get/([0-9a-zA-Z-]{1,5})$#', $path, $matches)) {
            handleGet($matches[1]);
        } else {
            hl_error('not_found', '未知的端点', 404);
        }
        break;
}

/**
 * POST /headless/api/create - 创建短链
 */
function handleCreate(): void
{
    global $createService, $authContext;

    $input = json_decode($GLOBALS['RAW_INPUT'] ?? '{}', true);
    if (!is_array($input)) {
        try { completeAuthentication(false); } catch (\Throwable $e) { error_log('[headless] completeAuthentication: ' . $e->getMessage()); }
        hl_error('bad_request', '无效的请求体', 400);
    }

    $result = $createService->execute($authContext, $input);

    if (!$result->ok) {
        try { completeAuthentication(false); } catch (\Throwable $e) { error_log('[headless] completeAuthentication: ' . $e->getMessage()); }
        hl_error($result->code ?? 'error', $result->message ?? '未知错误', $result->httpStatus ?? 400);
    }

    completeAuthentication(true);
    echo json_encode($result->data, JSON_UNESCAPED_UNICODE);
}

/**
 * POST /headless/api/delete - 删除短链
 */
function handleDelete(): void
{
    global $deleteService, $authContext;

    $input = json_decode($GLOBALS['RAW_INPUT'] ?? '{}', true);
    if (!is_array($input)) {
        try { completeAuthentication(false); } catch (\Throwable $e) { error_log('[headless] completeAuthentication: ' . $e->getMessage()); }
        hl_error('bad_request', '无效的请求体', 400);
    }

    if (!isset($input['code']) || !is_string($input['code'])) {
        try { completeAuthentication(false); } catch (\Throwable $e) { error_log('[headless] completeAuthentication: ' . $e->getMessage()); }
        hl_error('invalid_code', '短链后缀必须为字符串', 400);
    }
    $code = trim($input['code']);
    if ($code === '') {
        try { completeAuthentication(false); } catch (\Throwable $e) { error_log('[headless] completeAuthentication: ' . $e->getMessage()); }
        hl_error('missing_code', '缺少短链后缀', 400);
    }

    $result = $deleteService->execute($authContext, $code);

    if (!$result->ok) {
        try { completeAuthentication(false); } catch (\Throwable $e) { error_log('[headless] completeAuthentication: ' . $e->getMessage()); }
        hl_error($result->code ?? 'error', $result->message ?? '未知错误', $result->httpStatus ?? 400);
    }

    completeAuthentication(true);
    echo json_encode($result->data, JSON_UNESCAPED_UNICODE);
}

/**
 * GET /headless/api/list - 列出所有短链
 *
 * 返回 v1.11.0 的分组结构 {permanent: [...], temporary: [...]}，
 * 保证既有脚本与集成无需修改即可升级到 v2。
 */
function handleList(): void
{
    global $queryService, $authContext;

    $result = $queryService->listGrouped($authContext);

    if (!$result->ok) {
        try { completeAuthentication(false); } catch (\Throwable $e) { error_log('[headless] completeAuthentication: ' . $e->getMessage()); }
        hl_error($result->code ?? 'error', $result->message ?? '未知错误', $result->httpStatus ?? 400);
    }

    completeAuthentication(true);
    echo json_encode($result->data, JSON_UNESCAPED_UNICODE);
}

/**
 * GET /headless/api/stat - 获取配额状态
 */
function handleStat(): void
{
    global $queryService, $authContext;

    $result = $queryService->stat($authContext);

    if (!$result->ok) {
        try { completeAuthentication(false); } catch (\Throwable $e) { error_log('[headless] completeAuthentication: ' . $e->getMessage()); }
        hl_error($result->code ?? 'error', $result->message ?? '未知错误', $result->httpStatus ?? 400);
    }

    completeAuthentication(true);
    echo json_encode($result->data, JSON_UNESCAPED_UNICODE);
}

/**
 * GET /headless/api/get/{code} - 获取单条短链
 */
function handleGet(string $code): void
{
    global $queryService, $authContext;

    $result = $queryService->get($authContext, $code);

    if (!$result->ok) {
        try { completeAuthentication(false); } catch (\Throwable $e) { error_log('[headless] completeAuthentication: ' . $e->getMessage()); }
        hl_error($result->code ?? 'error', $result->message ?? '未知错误', $result->httpStatus ?? 400);
    }

    completeAuthentication(true);
    echo json_encode($result->data, JSON_UNESCAPED_UNICODE);
}
