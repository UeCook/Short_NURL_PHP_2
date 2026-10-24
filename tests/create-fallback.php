<?php
// 由 run.php 加载：真实冷文件 + 可控热侧故障，不依赖 OpenResty。

use ShortURL\Core\AuthContext;
use ShortURL\Core\CreateLinkService;
use ShortURL\Core\DeleteLinkService;
use ShortURL\Core\InternalApi;
use ShortURL\Core\JsonStore;

class FallbackTestApi extends InternalApi
{
    public bool $beginOk = true;
    public bool $setOk = true;
    public bool $deleteOk = true;
    public array $hot = [];
    public array $calls = [];
    public ?JsonStore $cold = null;
    public bool $sawColdBeforeSet = false;

    public function __construct() { parent::__construct([]); }
    public function beginMutation(string $code): bool
    {
        $this->calls[] = 'begin';
        return $this->beginOk;
    }
    public function endMutation(string $code): void { $this->calls[] = 'end'; }
    public function setWithRetry(string $code, string $url, int $ttl, string $expStr, int $maxRetries = 3): bool
    {
        $this->calls[] = 'set';
        $this->sawColdBeforeSet = isset($this->cold->read()[$code]);
        // 模拟服务端已执行但响应丢失，false 不等于未产生副作用。
        $this->hot[$code] = $url;
        return $this->setOk;
    }
    public function deleteWithRetry(string $code, string $type = '', int $maxRetries = 3): bool
    {
        $this->calls[] = 'delete';
        if ($this->deleteOk) unset($this->hot[$code]);
        return $this->deleteOk;
    }
}

class FallbackTestStore extends JsonStore
{
    public bool $failWrite = false;
    public function writeLocked(array $data): void
    {
        if ($this->failWrite) throw new RuntimeException('injected cold commit failure');
        parent::writeLocked($data);
    }
}

echo "▸ CreateLinkService 冷存储兜底测试\n";
$fallbackDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'shorturl-fallback-' . bin2hex(random_bytes(6));
if (!mkdir($fallbackDir, 0700, true)) throw new RuntimeException('无法创建兜底测试目录');
$fallbackPerm = new FallbackTestStore($fallbackDir . '/perm.json');
$fallbackTemp = new FallbackTestStore($fallbackDir . '/temp.json');
$fallbackConfig = require __DIR__ . '/../core/config.php';
$fallbackConfig['domain'] = 'https://short.example.com';
$fallbackAuth = new AuthContext('resident', false, 'test');

try {
    // 覆盖默认/显式关闭/开启 × mutation 成败 × 热写成败 × 永久/临时。
    foreach ([null, false, true] as $enabled) {
        foreach ([false, true] as $beginOk) {
            foreach ([false, true] as $setOk) {
                foreach ([0, 3600] as $ttl) {
                    $fallbackPerm->write([]);
                    $fallbackTemp->write([]);
                    $cfg = $fallbackConfig;
                    if ($enabled === null) unset($cfg['cold_fallback']);
                    else $cfg['cold_fallback'] = $enabled;
                    $api = new FallbackTestApi();
                    $api->beginOk = $beginOk;
                    $api->setOk = $setOk;
                    $api->cold = $ttl ? $fallbackTemp : $fallbackPerm;
                    $service = new CreateLinkService($cfg, $fallbackPerm, $fallbackTemp, $api);
                    $response = $service->execute($fallbackAuth, ['url' => 'https://example.com/test', 'code' => 'test', 'ttl' => $ttl]);
                    $label = 'fallback=' . var_export($enabled, true) . " begin={$beginOk} set={$setOk} ttl={$ttl}";
                    $success = $enabled === true || ($beginOk && $setOk);
                    assert_equals($success, $response->ok, "$label 成功状态");
                    assert_equals($success, isset($api->cold->read()['test']), "$label 实际冷提交");
                    assert_true(!$api->sawColdBeforeSet, "$label 保持先热后冷");
                    if ($success) {
                        assert_equals($setOk, $response->data['synced'], "$label 同步确认");
                        assert_equals(!$setOk, is_string($response->data['warning']), "$label 降级告警");
                        assert_true(!in_array('delete', $api->calls, true), "$label 冷提交成功不补偿热侧");
                        if ($ttl) assert_equals($response->data['exp'], $fallbackTemp->read()['test']['t'], '临时过期时间持久化');
                    } else {
                        assert_equals(503, $response->httpStatus, "$label 严格模式 503");
                        assert_true(!isset($api->hot['test']), "$label 不遗留已执行的热写");
                    }
                }
            }
        }
    }

    foreach ([false, true] as $enabled) {
        foreach ([false, true] as $setOk) {
            $fallbackPerm->write([]);
            $fallbackTemp->write([]);
            $fallbackPerm->failWrite = true;
            $cfg = $fallbackConfig;
            $cfg['cold_fallback'] = $enabled;
            $api = new FallbackTestApi();
            $api->cold = $fallbackPerm;
            $api->setOk = $setOk;
            $service = new CreateLinkService($cfg, $fallbackPerm, $fallbackTemp, $api);
            $response = $service->execute($fallbackAuth, ['url' => 'https://example.com/test', 'code' => 'test']);
            assert_equals($setOk || $enabled ? 500 : 503, $response->httpStatus, '冷提交失败不能报降级成功');
            assert_equals([], $fallbackPerm->read(), '冷提交失败无新冷记录');
            assert_equals([], $api->hot, '冷提交失败清理成功/未确认热写');
            $fallbackPerm->failWrite = false;
        }
    }

    $existing = ['test' => ['id' => 'test', 'url' => 'https://example.com/test', 'lurl' => 'https://short.example.com/test']];
    foreach ([false, true] as $enabled) {
        foreach ([false, true] as $beginOk) {
            foreach ([false, true] as $setOk) {
                $fallbackPerm->write($existing);
                $before = file_get_contents($fallbackPerm->getPath());
                $cfg = $fallbackConfig;
                $cfg['cold_fallback'] = $enabled;
                $api = new FallbackTestApi();
                $api->cold = $fallbackPerm;
                $api->beginOk = $beginOk;
                $api->setOk = $setOk;
                $service = new CreateLinkService($cfg, $fallbackPerm, $fallbackTemp, $api);
                $response = $service->execute($fallbackAuth, ['url' => 'https://example.com/test']);
                assert_equals($enabled || ($beginOk && $setOk), $response->ok, '去重遵循开关');
                assert_equals($before, file_get_contents($fallbackPerm->getPath()), '去重不改冷文件');
                assert_true(!in_array('delete', $api->calls, true), '去重不补偿删除已有记录');
                if ($response->ok) {
                    assert_true($response->data['dedup'], '去重标志');
                    assert_equals($setOk, $response->data['synced'], '去重同步状态');
                }
            }
        }
    }

    $cfg['cold_fallback'] = true;
    $cfg['perm_limit'] = 1;
    $api = new FallbackTestApi();
    $api->cold = $fallbackPerm;
    $service = new CreateLinkService($cfg, $fallbackPerm, $fallbackTemp, $api);
    $response = $service->execute($fallbackAuth, ['url' => 'https://example.com/other', 'code' => 'test']);
    assert_equals('conflict', $response->code, '兜底不绕过短码冲突');
    $response = $service->execute($fallbackAuth, ['url' => 'https://example.com/other', 'code' => 'more']);
    assert_equals('quota_exceeded', $response->code, '兜底不绕过冷配额');
    assert_equals([], $api->calls, '校验失败不调用热侧');

    $api->deleteOk = false;
    $deleteService = new DeleteLinkService($fallbackPerm, $fallbackTemp, $api);
    $response = $deleteService->execute($fallbackAuth, 'test');
    assert_equals(503, $response->httpStatus, '删除仍为严格策略');
    assert_equals($existing, $fallbackPerm->read(), '热删失败保留冷记录');
} finally {
    foreach (glob($fallbackDir . '/.operations/*') as $file) unlink($file);
    if (is_dir($fallbackDir . '/.operations')) rmdir($fallbackDir . '/.operations');
    foreach (glob($fallbackDir . '/*') as $file) unlink($file);
    rmdir($fallbackDir);
}
