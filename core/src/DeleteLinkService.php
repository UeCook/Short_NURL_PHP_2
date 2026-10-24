<?php
/**
 * DeleteLinkService — 删除短链服务
 *
 * 锁获取异常被捕获并转换为 BizResponse::error()
 */

namespace ShortURL\Core;

class DeleteLinkService
{
    private JsonStore $permStore;
    private JsonStore $tempStore;
    private InternalApi $internalApi;
    private array $config;

    public function __construct(JsonStore $permStore, JsonStore $tempStore, InternalApi $internalApi, array $config = [])
    {
        $this->permStore = $permStore;
        $this->tempStore = $tempStore;
        $this->internalApi = $internalApi;
        $this->config = $config;
    }

    /**
     * 执行删除短链
     *
     * @param AuthContext $auth 认证上下文
     * @param string $code 短码
     * @return BizResponse
     */
    public function execute(AuthContext $auth, string $code): BizResponse
    {
        // 一次性密钥禁止进行短链管理
        if ($auth->keyType === 'onetime') {
            return BizResponse::error('onetime_forbidden', '一次性密钥无权进行短链管理', 403);
        }

        if (empty($code)) {
            return BizResponse::error('missing_code', '缺少短链后缀');
        }
        $code = strtolower($code);

        $errorResponse = null;
        $deletedEntry = null;
        $deletedType = null;
        $hotDeleted = false;
        $mutationStarted = false;
        $operationLock = null;
        $synced = false;
        $coldFallback = (bool)($this->config['cold_fallback'] ?? false);

        try {
            JsonStore::withBothLocks($this->permStore, $this->tempStore, function (&$allData) use (
                $code, &$errorResponse, &$deletedEntry, &$deletedType, &$operationLock
            ) {
                $permPath = $this->permStore->getPath();
                $tempPath = $this->tempStore->getPath();
                $writePath = null;

                if (isset($allData[$tempPath][$code])) {
                    $deletedType = 'temp';
                    $writePath = $tempPath;
                } elseif (isset($allData[$permPath][$code])) {
                    $deletedType = 'perm';
                    $writePath = $permPath;
                } else {
                    $errorResponse = BizResponse::error('not_found', '该短链不存在', 404);
                    return ['write' => [], 'result' => false];
                }

                $deletedEntry = $allData[$writePath][$code];
                if (!is_array($deletedEntry)) {
                    $errorResponse = BizResponse::error('data_corrupted', '数据条目损坏', 500);
                    return ['write' => [], 'result' => false];
                }
                $operationLock = new LinkOperationLock($this->permStore->getPath(), 'code:' . $code);
                if (!$operationLock->acquired()) {
                    $errorResponse = BizResponse::error('operation_in_progress', '该短码正在操作，请稍后重试', 409);
                    return ['write' => [], 'result' => false];
                }
                return ['write' => [], 'result' => true];
            });
            if ($errorResponse) return $errorResponse;
            $mutationStarted = $this->internalApi->beginMutation($code);
            if (!$mutationStarted && !$coldFallback) {
                return BizResponse::error('hot_store_unavailable', '热存储暂不可用，短链未删除', 503);
            }
            if (!$mutationStarted) error_log("[delete] mutation 未确认，继续冷删除兜底: code={$code}");
            $hotDeleted = true; // 已尝试，超时也可能已在热侧执行。
            $synced = $this->internalApi->deleteWithRetry($code, $deletedType);
            if (!$synced && !$coldFallback) {
                $this->restoreHotEntry($code, $deletedType, $deletedEntry);
                return BizResponse::error('hot_store_unavailable', '热存储暂不可用，短链未删除', 503);
            }
            JsonStore::withBothLocks($this->permStore, $this->tempStore, function (&$allData) use ($code, $deletedType) {
                $writePath = ($deletedType === 'temp' ? $this->tempStore : $this->permStore)->getPath();
                unset($allData[$writePath][$code]);
                if ($deletedType === 'temp') {
                    cleanExpiredEntries($allData[$writePath]);
                }
                return ['write' => [$writePath], 'result' => true];
            });
        } catch (\Throwable $e) {
            error_log('[delete] 冷存储提交失败: ' . $e->getMessage());
            if ($hotDeleted && is_array($deletedEntry) && isset($deletedEntry['url'])) {
                $this->restoreHotEntry($code, $deletedType, $deletedEntry);
            }
            return BizResponse::error('write_failed', '服务器内部错误', 500);
        } finally {
            if ($mutationStarted) {
                $this->internalApi->endMutation($code);
            }
            if ($operationLock) $operationLock->release();
        }

        if ($errorResponse) return $errorResponse;

        if (!$synced) error_log("[delete] 冷记录已删除，热同步未确认: code={$code}");
        return BizResponse::success([
            'ok'     => true,
            'synced' => $synced,
            'warning' => $synced ? null : '冷存储记录已删除，但未确认热存储同步，原短链可能仍可访问。',
        ]);
    }

    private function restoreHotEntry(string $code, ?string $type, array $entry): void
    {
        if (!isset($entry['url']) || !is_string($entry['url']) || $entry['url'] === '') {
            error_log("[delete] 无法恢复损坏的冷记录: code={$code}");
            return;
        }
        $url = $entry['url'];
        if (!preg_match('#^https?://#i', $url) || preg_match('/[\x00-\x1f\x7f\s"<>]/', $url)) {
            error_log("[delete] 无法恢复非法 URL 的冷记录: code={$code}");
            return;
        }
        $ttl = 0;
        $exp = '0';
        if ($type === 'temp') {
            $exp = is_string($entry['t'] ?? null) ? $entry['t'] : '';
            $expiresAt = $exp !== '' ? strtotime($exp) : false;
            if ($expiresAt === false || $expiresAt <= time()) return;
            $ttl = max(1, $expiresAt - time());
        }

        if (!$this->internalApi->setWithRetry($code, $url, $ttl, $exp)) {
            $detail = $this->internalApi->getLastError();
            error_log("[delete] 冷写失败后的热存储恢复失败: code={$code}, 原因：" .
                ($detail['message'] ?? '未知错误'));
        }
    }
}
