#!/usr/bin/env php
<?php
/**
 * Short_NURL v2.0 测试入口
 *
 * 用法：php tests/run.php
 *
 * 轻量断言脚本，无第三方依赖
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "错误：此脚本必须从命令行运行。\n");
    exit(1);
}

// 加载核心层
require_once __DIR__ . '/../core/src/autoload.php';

use ShortURL\Core\KeyVerifyResult;
use ShortURL\Core\AuthContext;
use ShortURL\Core\AuthResult;
use ShortURL\Core\BizResponse;
use ShortURL\Core\JsonStore;
use ShortURL\Core\KeyStore;
use ShortURL\Core\InternalApi;
use ShortURL\Core\LinkQueryService;
use ShortURL\Core\DeleteLinkService;
use function ShortURL\Core\base58_encode;
use function ShortURL\Core\formatIso8601;
use function ShortURL\Core\isExpired;
use function ShortURL\Core\allowedKeyTypes;
use function ShortURL\Core\auth_hash;

// 测试计数器
$passed = 0;
$failed = 0;
$errors = [];

// 断言函数
function assert_true(bool $condition, string $message): void
{
    global $passed, $failed, $errors;
    if ($condition) {
        $passed++;
        echo "  ✓ {$message}\n";
    } else {
        $failed++;
        $errors[] = $message;
        echo "  ✗ {$message}\n";
    }
}

function assert_equals($expected, $actual, string $message): void
{
    assert_true($expected === $actual, "{$message} (expected: " . var_export($expected, true) . ", actual: " . var_export($actual, true) . ")");
}

// 开始测试
echo "═══════════════════════════════════════════════════════════════\n";
echo "Short_NURL v2.0 测试套件\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

// ── KeyVerifyResult 测试 ─────────────────────────────────
echo "▸ KeyVerifyResult 测试\n";

$result = KeyVerifyResult::success('resident');
assert_true($result->ok, "success() 返回 ok=true");
assert_equals('resident', $result->keyType, "success() 设置 keyType");
assert_true($result->error === null, "success() error 为 null");

$result = KeyVerifyResult::success('onetime', 'reservation');
assert_equals('reservation', $result->reservationToken, "success() 设置预约令牌");

$result = KeyVerifyResult::invalid();
assert_true(!$result->ok, "invalid() 返回 ok=false");
assert_equals('invalid', $result->error, "invalid() 设置 error");

$result = KeyVerifyResult::wrongChannel();
assert_true(!$result->ok, "wrongChannel() 返回 ok=false");
assert_equals('wrong_channel', $result->error, "wrongChannel() 设置 error");

$result = KeyVerifyResult::backendError();
assert_true(!$result->ok, "backendError() 返回 ok=false");
assert_equals('backend_error', $result->error, "backendError() 设置 error");

echo "\n";

// ── AuthContext 测试 ──────────────────────────────────────
echo "▸ AuthContext 测试\n";

$context = new AuthContext(
    keyType: 'resident',
    consumed: false,
    keyPrefix: 'su_test12'
);
assert_equals('resident', $context->keyType, "AuthContext keyType");
assert_true(!$context->consumed, "AuthContext consumed=false");
assert_equals('su_test12', $context->keyPrefix, "AuthContext keyPrefix");

$context = new AuthContext(
    keyType: 'onetime',
    consumed: true,
    keyPrefix: 'su_onetm'
);
assert_equals('onetime', $context->keyType, "AuthContext onetime keyType");
assert_true($context->consumed, "AuthContext consumed=true");

echo "\n";

// ── AuthResult 测试 ───────────────────────────────────────
echo "▸ AuthResult 测试\n";

$context = new AuthContext('resident', false, 'su_test');
$result = AuthResult::ok($context);
assert_true($result->success, "ok() 返回 success=true");
assert_true($result->authContext !== null, "ok() 包含 authContext");
assert_true($result->reason === null, "ok() reason 为 null");

$result = AuthResult::fail('invalid');
assert_true(!$result->success, "fail() 返回 success=false");
assert_true($result->authContext === null, "fail() authContext 为 null");
assert_equals('invalid', $result->reason, "fail() 设置 reason");

echo "\n";

// ── BizResponse 测试 ──────────────────────────────────────
echo "▸ BizResponse 测试\n";

$result = BizResponse::success(['key' => 'value']);
assert_true($result->ok, "success() 返回 ok=true");
assert_equals(['key' => 'value'], $result->data, "success() 设置 data");
assert_true($result->code === null, "success() code 为 null");
assert_true($result->message === null, "success() message 为 null");

$result = BizResponse::error('test_error', '测试错误', 400);
assert_true(!$result->ok, "error() 返回 ok=false");
assert_true($result->data === null, "error() data 为 null");
assert_equals('test_error', $result->code, "error() 设置 code");
assert_equals('测试错误', $result->message, "error() 设置 message");
assert_equals(400, $result->httpStatus, "error() 设置 httpStatus");

echo "\n";

// ── Helpers 测试 ──────────────────────────────────────────
echo "▸ Helpers 测试\n";

// 测试 isExpired
assert_true(!isExpired(null), "isExpired(null) 返回 false");
assert_true(!isExpired('0'), "isExpired('0') 返回 false");
assert_true(!isExpired(''), "isExpired('') 返回 false");
assert_true(isExpired('2020-01-01T00:00:00+08:00'), "isExpired(过去时间) 返回 true");
assert_true(!isExpired(formatIso8601(time() + 3600, '+08:00')), "isExpired(未来时间) 返回 false");
assert_true(isExpired('not-a-date'), "isExpired(无效字符串) 返回 true");

// 测试 formatIso8601
$ts = 1700000000;
$result = formatIso8601($ts, '+08:00');
assert_equals('2023-11-15T06:13:20+08:00', $result, "formatIso8601 精确时间值");

try {
    formatIso8601($ts, '+24:00');
    assert_true(false, "formatIso8601 拒绝无效偏移");
} catch (InvalidArgumentException $e) {
    assert_true(true, "formatIso8601 拒绝无效偏移");
}

assert_equals('', base58_encode(''), "Base58 空输入");
assert_equals('1', base58_encode("\x00"), "Base58 保留前导零");
assert_equals('2g', base58_encode('a'), "Base58 已知向量");

// 测试 allowedKeyTypes
assert_equals(['resident', 'onetime'], allowedKeyTypes('webui', 'create'), "webui/create 允许 resident,onetime");
assert_equals(['resident'], allowedKeyTypes('webui', 'delete'), "webui/delete 允许 resident");
assert_equals(['resident'], allowedKeyTypes('webui', 'list'), "webui/list 允许 resident");
assert_equals(['resident'], allowedKeyTypes('webui', 'stat'), "webui/stat 允许 resident");
assert_equals(['resident', 'onetime', 'service'], allowedKeyTypes('headless', 'create'), "headless/create 允许 resident,onetime,service");
assert_equals(['resident', 'service'], allowedKeyTypes('headless', 'delete'), "headless/delete 允许 resident,service");
assert_equals(['resident', 'service'], allowedKeyTypes('headless', 'list'), "headless/list 允许 resident,service");
assert_equals(['resident', 'service'], allowedKeyTypes('headless', 'stat'), "headless/stat 允许 resident,service");
assert_equals(['resident', 'service'], allowedKeyTypes('headless', 'get'), "headless/get 允许 resident,service");

try {
    allowedKeyTypes('webui', 'get');
    assert_true(false, "webui/get 应抛出异常");
} catch (\InvalidArgumentException $e) {
    assert_true(true, "webui/get 抛出 InvalidArgumentException");
}

try {
    allowedKeyTypes('unknown', 'create');
    assert_true(false, "未知来源应抛出异常");
} catch (\InvalidArgumentException $e) {
    assert_true(true, "未知来源抛出 InvalidArgumentException");
}

$testDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'shorturl-v2-' . bin2hex(random_bytes(6));
if (!mkdir($testDir, 0700, true)) {
    throw new RuntimeException('无法创建测试目录');
}
$storePath = $testDir . DIRECTORY_SEPARATOR . 'store.json';
$store = new JsonStore($storePath, '+08:00');
$store->write(['abc' => ['id' => 'abc']]);
assert_equals(['abc' => ['id' => 'abc']], $store->read(), "JsonStore 加锁写入并读取");

$keysPath = $testDir . DIRECTORY_SEPARATOR . 'keys.json';
$keyStore = new KeyStore($keysPath, '+08:00', 7, 1);
$onetimeKeys = $keyStore->fillPool();
assert_equals(1, count($onetimeKeys), "KeyStore 生成一次性密钥");
$reservation = $keyStore->verify($onetimeKeys[0], ['onetime']);
assert_true($reservation->ok && $reservation->reservationToken !== null, "一次性密钥验证后进入预约状态");
assert_true($keyStore->completeOnetime($reservation->reservationToken, false), "失败业务释放一次性密钥预约");
$reservation = $keyStore->verify($onetimeKeys[0], ['onetime']);
assert_true($reservation->ok && $reservation->reservationToken !== null, "释放后一次性密钥可重试");
assert_true($keyStore->completeOnetime($reservation->reservationToken, true), "成功业务提交一次性密钥消费");
assert_true(!$keyStore->verify($onetimeKeys[0], ['onetime'])->ok, "消费后一次性密钥不可复用");
@unlink($keysPath . '.bak');
@unlink($keysPath . '.lock');
@unlink($keysPath);
@unlink($storePath . '.bak');
@unlink($storePath . '.lock');
@unlink($storePath);
@rmdir($testDir);

echo "\n";

// ── LinkQueryService 测试 ─────────────────────────────────
echo "▸ LinkQueryService 测试\n";

// 创建临时目录 fixture
$queryTestDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'shorturl-query-' . bin2hex(random_bytes(6));
if (!mkdir($queryTestDir, 0700, true)) {
    throw new RuntimeException('无法创建查询测试目录');
}

$permPath = $queryTestDir . DIRECTORY_SEPARATOR . 'perm.json';
$tempPath = $queryTestDir . DIRECTORY_SEPARATOR . 'temp.json';
$queryKeysPath = $queryTestDir . DIRECTORY_SEPARATOR . 'keys.json';

// 创建 perm.json fixture
$permStore = new JsonStore($permPath, '+08:00');
$permStore->write([
    'abc1' => ['id' => 'abc1', 'url' => 'https://example.com/1', 'lurl' => 'https://long.example.com/1'],
    'abc2' => ['id' => 'abc2', 'url' => 'https://example.com/2', 'lurl' => 'https://long.example.com/2'],
]);

// 创建 temp.json fixture（1条活跃 + 1条过期）
$tempStore = new JsonStore($tempPath, '+08:00');
$futureExp = formatIso8601(time() + 3600, '+08:00');
$pastExp = formatIso8601(time() - 3600, '+08:00');
$tempStore->write([
    'tmp1' => ['id' => 'tmp1', 'url' => 'https://temp.com/1', 'lurl' => 'https://long.temp.com/1', 't' => $futureExp],
    'tmp2' => ['id' => 'tmp2', 'url' => 'https://temp.com/2', 'lurl' => 'https://long.temp.com/2', 't' => $pastExp],
]);

// 创建 InternalApi mock（使用假配置，确保 hot_available === false）
$fakeCfg = [
    'internal_host' => 'http://127.0.0.1:1',
    'internal_timeout' => 0.2,
    'perm_limit' => 10000,
    'temp_limit' => 500,
    'svc_perm_limit' => 18000,
    'svc_temp_limit' => 1500,
];
$internalApi = new InternalApi($fakeCfg);

// 创建服务实例
$queryService = new LinkQueryService($fakeCfg, $permStore, $tempStore, $internalApi);

// 测试 list() - resident AuthContext
$residentCtx = new AuthContext('resident', false, 'su_test');
$listResult = $queryService->list($residentCtx);
assert_true($listResult->ok, "list() 成功返回");
assert_equals(3, count($listResult->data), "list() 返回 3 条记录（2 perm + 1 活跃 temp）");

// 验证过期 temp 被过滤
$hasExpired = false;
foreach ($listResult->data as $item) {
    if ($item['id'] === 'tmp2') {
        $hasExpired = true;
    }
}
assert_true(!$hasExpired, "list() 过期 temp 被过滤");

// 验证 perm 条目 exp === 'permanent'
$byId = [];
foreach ($listResult->data as $item) { $byId[$item['id']] = $item; }
assert_true(isset($byId['abc1']), "list() 包含 abc1");
assert_equals('permanent', $byId['abc1']['exp'], "perm 条目 exp === 'permanent'");

// 验证 temp 活跃条目 exp 为 ISO 串
assert_true(isset($byId['tmp1']), "list() 包含 tmp1");
assert_equals($futureExp, $byId['tmp1']['exp'], "temp 活跃条目 exp 为 ISO 串");

// 测试 listGrouped()
$groupedResult = $queryService->listGrouped($residentCtx);
assert_true($groupedResult->ok, "listGrouped() 成功返回");
assert_true(isset($groupedResult->data['permanent'], $groupedResult->data['temporary']), "listGrouped() 返回 permanent/temporary 分组");
assert_equals(['permanent', 'temporary'], array_keys($groupedResult->data), "listGrouped() 契约键为 permanent/temporary（Headless list 兼容 v1.11.0）");
assert_equals(2, count($groupedResult->data['permanent']), "listGrouped() permanent 有 2 条");
assert_equals(1, count($groupedResult->data['temporary']), "listGrouped() temporary 有 1 条");

// 测试 get() - perm 命中
$getResult = $queryService->get($residentCtx, 'abc1');
assert_true($getResult->ok, "get() perm 命中成功");
assert_equals('permanent', $getResult->data['exp'], "get() perm 返回 exp=permanent");

// 测试 get() - temp 活跃命中
$getResult = $queryService->get($residentCtx, 'tmp1');
assert_true($getResult->ok, "get() temp 活跃命中成功");
assert_equals($futureExp, $getResult->data['exp'], "get() temp 返回正确 exp");

// 测试 get() - temp 过期 → 404 expired
$getResult = $queryService->get($residentCtx, 'tmp2');
assert_true(!$getResult->ok, "get() temp 过期返回失败");
assert_equals('expired', $getResult->code, "get() temp 过期返回 expired");

// 测试 get() - 未命中 → 404 not_found
$getResult = $queryService->get($residentCtx, 'nonexistent');
assert_true(!$getResult->ok, "get() 未命中返回失败");
assert_equals('not_found', $getResult->code, "get() 未命中返回 not_found");

// 测试 stat() - service AuthContext 选中 svc_perm_limit/svc_temp_limit
$serviceCtx = new AuthContext('service', false, 'su_svc');
$statResult = $queryService->stat($serviceCtx);
assert_true($statResult->ok, "stat() 成功返回");
assert_equals(18000, $statResult->data['perm_limit'], "stat() service 使用 svc_perm_limit");
assert_equals(1500, $statResult->data['temp_limit'], "stat() service 使用 svc_temp_limit");
assert_true($statResult->data['hot_available'] === false, "stat() hot_available === false（假配置）");

// 测试 stat() - resident AuthContext 使用默认 limit
$statResult = $queryService->stat($residentCtx);
assert_true($statResult->ok, "stat() resident 成功返回");
assert_equals(10000, $statResult->data['perm_limit'], "stat() resident 使用 perm_limit");
assert_equals(500, $statResult->data['temp_limit'], "stat() resident 使用 temp_limit");

// 测试 onetime AuthContext → list/listGrouped/stat 均 403
$onetimeCtx = new AuthContext('onetime', false, 'su_onetm');
$listResult = $queryService->list($onetimeCtx);
assert_true(!$listResult->ok, "onetime list() 返回失败");
assert_equals('onetime_forbidden', $listResult->code, "onetime list() 返回 onetime_forbidden");

$groupedResult = $queryService->listGrouped($onetimeCtx);
assert_true(!$groupedResult->ok, "onetime listGrouped() 返回失败");
assert_equals('onetime_forbidden', $groupedResult->code, "onetime listGrouped() 返回 onetime_forbidden");

$statResult = $queryService->stat($onetimeCtx);
assert_true(!$statResult->ok, "onetime stat() 返回失败");
assert_equals('onetime_forbidden', $statResult->code, "onetime stat() 返回 onetime_forbidden");

// 测试 KeyStore 预约超时作废
$expiredKeysDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'shorturl-expired-' . bin2hex(random_bytes(6));
if (!mkdir($expiredKeysDir, 0700, true)) {
    throw new RuntimeException('无法创建过期测试目录');
}
$expiredKeysPath = $expiredKeysDir . DIRECTORY_SEPARATOR . 'keys.json';

// 手写 keys.json fixture，池内 slot 含 key_hash、reserved_at = time() - 61、reservation_token
$rawKey = 'su_testExpiredKey123456789';
$keyHash = auth_hash($rawKey);
$expiredKeysData = [
    'resident' => null,
    'service' => null,
    'onetime_pool' => [
        [
            'key_hash' => $keyHash,
            'key_prefix' => substr($rawKey, 0, 8),
            'created' => formatIso8601(time() - 100, '+08:00'),
            'reserved_at' => time() - 61,
            'reservation_token' => 'test_token_123',
        ],
    ],
];
file_put_contents($expiredKeysPath, json_encode($expiredKeysData, JSON_PRETTY_PRINT));

$expiredKeyStore = new KeyStore($expiredKeysPath, '+08:00', 7, 1);
$verifyResult = $expiredKeyStore->verify($rawKey, ['onetime']);
assert_true(!$verifyResult->ok, "预约超时的 onetime key 返回 invalid");

// 验证文件中该 slot key_hash 被置 null
$keysData = json_decode(file_get_contents($expiredKeysPath), true);
assert_true($keysData['onetime_pool'][0]['key_hash'] === null, "预约超时后 slot key_hash 被置 null");

// 清理过期测试目录
@unlink($expiredKeysPath . '.bak');
@unlink($expiredKeysPath . '.lock');
@unlink($expiredKeysPath);
@rmdir($expiredKeysDir);

// 清理查询测试目录
@unlink($permPath . '.bak');
@unlink($permPath . '.lock');
@unlink($permPath);
@unlink($tempPath . '.bak');
@unlink($tempPath . '.lock');
@unlink($tempPath);
@unlink($queryKeysPath . '.bak');
@unlink($queryKeysPath . '.lock');
@unlink($queryKeysPath);
@rmdir($queryTestDir);

echo "\n";

// ── 过期边界回归测试 ──────────────────────────────────────
echo "▸ 过期边界回归测试\n";

// exp == time() 精确边界：应视为已过期（PHP <= time() 与 Lua is_expired(ep, now) 对齐）
$exactNow = formatIso8601(time(), '+08:00');
assert_true(isExpired($exactNow), "isExpired(恰好当前时间) === true（与 Lua <= 对齐）");

// 恰好过去 1 秒
$past1s = formatIso8601(time() - 1, '+08:00');
assert_true(isExpired($past1s), "isExpired(过去1秒) === true");

// 恰好未来 1 秒
$future1s = formatIso8601(time() + 1, '+08:00');
assert_true(!isExpired($future1s), "isExpired(未来1秒) === false");

// ── restoreHotEntry URL 校验回归测试 ──────────────────────
echo "▸ restoreHotEntry URL 校验回归测试\n";

// 构建测试 double：捕获 setWithRetry 调用
class TestInternalApi extends InternalApi {
    public array $setCalls = [];
    public function __construct() {}
    public function setWithRetry(string $code, string $url, int $ttl, string $expStr, int $maxRetries = 3): bool {
        $this->setCalls[] = ['code' => $code, 'url' => $url, 'ttl' => $ttl, 'exp' => $expStr];
        return true;
    }
    public function getLastError(): ?array { return null; }
}

$restoreApi = new TestInternalApi();
$restorePerm = new JsonStore(sys_get_temp_dir() . '/restore-test-perm.json', '+08:00');
$restoreTemp = new JsonStore(sys_get_temp_dir() . '/restore-test-temp.json', '+08:00');
$restoreService = new DeleteLinkService($restorePerm, $restoreTemp, $restoreApi, []);

// 使用反射测试私有方法 restoreHotEntry
$ref = new ReflectionMethod(DeleteLinkService::class, 'restoreHotEntry');
$ref->setAccessible(true);

// 恶意 URL 应被拒绝
$restoreApi->setCalls = [];
$ref->invoke($restoreService, 'test', 'perm', ['url' => 'javascript:alert(1)']);
assert_equals(0, count($restoreApi->setCalls), "javascript: URL 被拒绝");

$restoreApi->setCalls = [];
$ref->invoke($restoreService, 'test', 'perm', ['url' => "http://x\r\nSet-Cookie: a=1"]);
assert_equals(0, count($restoreApi->setCalls), "CRLF 注入 URL 被拒绝");

$restoreApi->setCalls = [];
$ref->invoke($restoreService, 'test', 'perm', ['url' => 'data:text/html,<script>']);
assert_equals(0, count($restoreApi->setCalls), "data: URL 被拒绝");

$restoreApi->setCalls = [];
$ref->invoke($restoreService, 'test', 'perm', ['url' => 'http://example.com/a b']);
assert_equals(0, count($restoreApi->setCalls), "含空格 URL 被拒绝");

$restoreApi->setCalls = [];
$ref->invoke($restoreService, 'test', 'perm', ['url' => '']);
assert_equals(0, count($restoreApi->setCalls), "空 URL 被拒绝");

// 正常 URL 应被接受
$restoreApi->setCalls = [];
$ref->invoke($restoreService, 'test', 'perm', ['url' => 'https://example.com/ok']);
assert_equals(1, count($restoreApi->setCalls), "正常 URL 被接受");
assert_equals('https://example.com/ok', $restoreApi->setCalls[0]['url'], "恢复 URL 正确传递");

// 清理测试临时文件
@unlink(sys_get_temp_dir() . '/restore-test-perm.json');
@unlink(sys_get_temp_dir() . '/restore-test-perm.json.bak');
@unlink(sys_get_temp_dir() . '/restore-test-perm.json.lock');
@unlink(sys_get_temp_dir() . '/restore-test-temp.json');
@unlink(sys_get_temp_dir() . '/restore-test-temp.json.bak');
@unlink(sys_get_temp_dir() . '/restore-test-temp.json.lock');

echo "\n";

require __DIR__ . '/create-fallback.php';
require __DIR__ . '/regressions.php';
require __DIR__ . '/cli-regressions.php';

// ── 结果汇总 ──────────────────────────────────────────────
echo "═══════════════════════════════════════════════════════════════\n";
echo "测试结果：{$passed} 通过, {$failed} 失败\n";
echo "═══════════════════════════════════════════════════════════════\n";

if ($failed > 0) {
    echo "\n失败的测试：\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
    exit(1);
}

exit(0);
