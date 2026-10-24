<?php
use ShortURL\Core\AuthContext;
use ShortURL\Core\CreateLinkService;
use ShortURL\Core\DeleteLinkService;
use ShortURL\Core\JsonStore;
use ShortURL\Core\KeyStore;
use ShortURL\Core\LinkOperationLock;

function removeRegressionFixture(string $dir): void
{
    foreach (scandir($dir) as $name) {
        if ($name === '.' || $name === '..') continue;
        $path = $dir . '/' . $name;
        if (is_dir($path)) removeRegressionFixture($path);
        else unlink($path);
    }
    rmdir($dir);
}

class RegressionApi extends FallbackTestApi
{
    public array $stores = [];
    public $duringSet = null;
    public $duringDelete = null;

    private function checkLocks(): void
    {
        foreach ($this->stores as $store) {
            $fp = fopen($store->getPath() . '.lock', 'c+');
            $free = flock($fp, LOCK_EX | LOCK_NB);
            assert_true($free, '网络调用期间可独立获取冷 Store 锁');
            if ($free) flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
    public function beginMutation(string $code): bool { $this->checkLocks(); return parent::beginMutation($code); }
    public function endMutation(string $code): void { $this->checkLocks(); parent::endMutation($code); }
    public function setWithRetry(string $code, string $url, int $ttl, string $expStr, int $maxRetries = 3): bool
    {
        $this->checkLocks();
        if ($this->duringSet) {
            $callback = $this->duringSet;
            $this->duringSet = null;
            $callback();
        }
        return parent::setWithRetry($code, $url, $ttl, $expStr, $maxRetries);
    }
    public function deleteWithRetry(string $code, string $type = '', int $maxRetries = 3): bool
    {
        $this->checkLocks();
        if ($this->duringDelete) {
            $callback = $this->duringDelete;
            $this->duringDelete = null;
            $callback();
        }
        return parent::deleteWithRetry($code, $type, $maxRetries);
    }
}

echo "▸ V2 回归修复测试\n";
$regDir = sys_get_temp_dir() . '/shorturl-regression-' . bin2hex(random_bytes(6));
mkdir($regDir, 0700, true);
try {
    $perm = new FallbackTestStore($regDir . '/perm.json');
    $temp = new FallbackTestStore($regDir . '/temp.json');
    $perm->write([]);
    $temp->write([]);
    $cfg = require __DIR__ . '/../core/config.php';
    $cfg['domain'] = 'https://short.example.com';
    $cfg['perm_limit'] = 1;
    $auth = new AuthContext('resident', false, 'test');
    $api = new RegressionApi();
    $api->cold = $perm;
    $api->stores = [$perm, $temp];
    $create = new CreateLinkService($cfg, $perm, $temp, $api);
    $api->duringSet = function () use ($create, $auth) {
        $same = $create->execute($auth, ['url' => 'https://example.com/same', 'code' => 'aaaa']);
        assert_equals('operation_in_progress', $same->code, '同码并发请求不能覆盖未提交操作');
        $other = $create->execute($auth, ['url' => 'https://example.com/other', 'code' => 'bbbb']);
        assert_true($other->ok, '其他短码可在热请求期间完成提交');
    };
    $result = $create->execute($auth, ['url' => 'https://example.com/a', 'code' => 'aaaa']);
    assert_equals('quota_exceeded', $result->code, '提交前重查并发变化的配额');
    assert_equals(['bbbb'], array_keys($perm->read()), '不覆盖并发提交的冷数据');
    assert_true(!isset($api->hot['aaaa']), '配额竞争失败清理本次热条目');

    $delete = new DeleteLinkService($perm, $temp, $api);
    $api->duringDelete = function () use ($create, $delete, $auth) {
        $same = $delete->execute($auth, 'bbbb');
        assert_equals('operation_in_progress', $same->code, '删除期间同码操作被阻止');
        $dedup = $create->execute($auth, ['url' => 'https://example.com/other']);
        assert_equals('operation_in_progress', $dedup->code, '删除期间去重不能恢复旧热条目');
    };
    assert_true($delete->execute($auth, 'bbbb')->ok, '热删成功后冷删');
    assert_equals([], $perm->read(), '冷删除实际提交');

    $api->duringSet = function () use ($create, $auth) {
        $sameUrl = $create->execute($auth, ['url' => 'https://example.com/auto']);
        assert_equals('operation_in_progress', $sameUrl->code, '同 URL 并发自动创建返回可重试冲突');
    };
    $auto = $create->execute($auth, ['url' => 'https://example.com/auto']);
    assert_true($auto->ok && !$auto->data['dedup'], '自动生成短码成功');
    $dedup = $create->execute($auth, ['url' => 'https://example.com/auto']);
    assert_true($dedup->ok && $dedup->data['dedup'], '自动创建结束后正确去重');
    assert_equals($auto->data['short_url'], $dedup->data['short_url'], '去重返回同一短链');
    assert_equals(1, count($perm->read()), '同目标未产生重复记录');

    foreach ([false, true] as $enabled) {
        foreach ([false, true] as $begin) {
            $entry = ['bbbb' => ['url' => 'https://example.com/b', 'lurl' => 'https://short.example.com/bbbb']];
            $perm->write($entry);
            $api->beginOk = $begin;
            $api->deleteOk = false;
            $delete = new DeleteLinkService($perm, $temp, $api, ['cold_fallback' => $enabled]);
            $result = $delete->execute($auth, 'bbbb');
            assert_equals($enabled, $result->ok, '删除兜底遵循配置及 mutation 失败');
            assert_equals($enabled ? [] : $entry, $perm->read(), '删除兜底真实落盘');
            if ($enabled) {
                assert_equals(false, $result->data['synced'], '删除未确认同步');
                assert_true(is_string($result->data['warning']), '删除降级返回告警');
            }
        }
    }
    $api->beginOk = true;
    $api->deleteOk = true;
    $perm->write(['bbbb' => ['url' => 'https://example.com/b']]);
    $perm->failWrite = true;
    $result = (new DeleteLinkService($perm, $temp, $api, ['cold_fallback' => true]))->execute($auth, 'bbbb');
    assert_equals(500, $result->httpStatus, '冷删失败不能返回降级成功');
    assert_equals('https://example.com/b', $api->hot['bbbb'], '冷删失败恢复热条目');
    $perm->failWrite = false;
    $perm->write(['bbbb' => ['id' => 'bbbb']]);
    $api->deleteOk = false;
    $result = (new DeleteLinkService($perm, $temp, $api))->execute($auth, 'bbbb');
    assert_equals(503, $result->httpStatus, '缺 url 的损坏记录不会触发恢复 TypeError');

    $keysPath = $regDir . '/keys.json';
    $keys = new KeyStore($keysPath, '+08:00', 7, 3);
    $rawKeys = $keys->fillPool();
    $old = $keys->verify($rawKeys[0], ['onetime']);
    $live = $keys->verify($rawKeys[1], ['onetime']);
    $data = json_decode(file_get_contents($keysPath), true);
    $data['onetime_pool'][0]['reserved_at'] = time() - 61;
    file_put_contents($keysPath, json_encode($data));
    $status = $keys->status();
    assert_equals(1, $status['onetime_count'], '状态只统计真正可用 key');
    assert_equals(1, $status['onetime_reserved_count'], '状态单列有效预约');
    assert_true($status['onetime_pool'][0]['consumed'], '状态访问主动作废超时预约');
    assert_true($status['onetime_pool'][1]['reserved'], '有效预约不回收');
    assert_equals(1, count($keys->fillPool()), '补回遗留预约占用容量');
    assert_true(!$keys->completeOnetime($old->reservationToken, false), '旧令牌不能释放补池后的新 key');
    assert_true(!$keys->verify($rawKeys[0], ['onetime'])->ok, '过期预约原 key 永久失效');
    assert_true($keys->completeOnetime($live->reservationToken, false), '正常预约仍可释放');
    $reserved = $keys->verify($rawKeys[2], ['onetime']);
    assert_true($reserved->ok && $reserved->reservationToken !== null, 'slot2 预约成功');
    $data = json_decode(file_get_contents($keysPath), true);
    $data['onetime_pool'][2]['reserved_at'] = time() - 61;
    file_put_contents($keysPath, json_encode($data));
    assert_equals(1, count($keys->fillPool()), 'fillPool 无需旧 key 再出现即可回收');
} finally {
    removeRegressionFixture($regDir);
}
