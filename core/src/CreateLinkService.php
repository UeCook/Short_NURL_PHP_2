<?php
/**
 * CreateLinkService — 创建短链服务
 *
 * 双 Store 锁内只选择和提交；网络同步及补偿在短码操作锁内、双 Store 锁外执行。
 * cold_fallback 开启时，热同步未确认仍允许冷提交；冷提交失败仍报错并尽力补偿。
 */

namespace ShortURL\Core;

class CreateLinkService
{
    private array $config;
    private JsonStore $permStore;
    private JsonStore $tempStore;
    private InternalApi $internalApi;

    public function __construct(array $config, JsonStore $permStore, JsonStore $tempStore, InternalApi $internalApi)
    {
        $this->config = $config;
        $this->permStore = $permStore;
        $this->tempStore = $tempStore;
        $this->internalApi = $internalApi;
    }

    /**
     * 执行创建短链
     *
     * @param AuthContext $auth 认证上下文
     * @param array $params 请求参数（url, ttl, code, len）
     * @return BizResponse
     */
    public function execute(AuthContext $auth, array $params): BizResponse
    {
        $configuredCapacity = ($this->config['perm_limit'] ?? 0)
            + ($this->config['temp_limit'] ?? 0)
            + ($this->config['svc_perm_limit'] ?? 0)
            + ($this->config['svc_temp_limit'] ?? 0);
        if ($configuredCapacity > 30000) {
            return BizResponse::error('config_error', '配置的总容量超过 30000', 500);
        }

        // 验证目标链接
        if (!isset($params['url']) || !is_string($params['url'])) {
            return BizResponse::error('missing_url', '目标链接必须为字符串');
        }
        $url = trim($params['url']);
        if ($url === '') {
            return BizResponse::error('missing_url', '缺少目标链接');
        }
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
            return BizResponse::error('invalid_url', '目标链接无效');
        }
        if (strlen($url) > 2048) {
            return BizResponse::error('url_too_long', '目标链接过长（最大 2048 字符）');
        }
        if (isPrivateUrl($url)) {
            return BizResponse::error('private_url', '目标链接指向内网地址，已被拒绝');
        }

        // 验证 TTL
        if (!array_key_exists('ttl', $params)) {
            $ttl = 0;
        } elseif (!is_int($params['ttl'])) {
            return BizResponse::error('invalid_ttl', 'TTL 必须为整数');
        } else {
            $ttl = $params['ttl'];
        }
        if ($ttl < 0 || $ttl > $this->config['ttl_max']) {
            return BizResponse::error('ttl_exceeded', 'TTL 超限');
        }
        $isTemp = $ttl > 0;

        $code = isset($params['code']) && is_string($params['code']) ? trim($params['code']) : '';
        $isCustom = !empty($code);

        // 服务密钥专用配置
        $isServiceKey = $auth->keyType === 'service';
        $svcCodeLength = $isServiceKey ? ($this->config['svc_code_length'] ?? 4) : 4;
        if ($isServiceKey && isset($params['len']) && in_array(intval($params['len']), [4, 5], true)) {
            $svcCodeLength = intval($params['len']);
        }
        $maxCodeLen = $isServiceKey ? 5 : 4;

        if ($isCustom) {
            $code = strtolower($code);
            if (!preg_match('/^[0-9a-z-]{1,' . $maxCodeLen . '}$/', $code)) {
                return BizResponse::error('invalid_code', '后缀格式错误');
            }
            if (in_array($code, $this->config['reserved_codes'])) {
                return BizResponse::error('reserved_code', '保留字');
            }
        }

        $now = time();
        $domain = $this->config['domain'];
        $tz = $this->config['tz_offset'];
        try {
            $exp_str = $isTemp ? formatIso8601($now + $ttl, $tz) : '0';
        } catch (\InvalidArgumentException $e) {
            error_log('[create] 时区配置无效: ' . $e->getMessage());
            return BizResponse::error('config_error', '时区配置无效', 500);
        }
        $shortUrl = $domain . '/' . $code;
        $entry = ['id' => $code, 'url' => $url, 'lurl' => $shortUrl];
        if ($isTemp) $entry['t'] = $exp_str;

        $store = $isTemp ? $this->tempStore : $this->permStore;
        $otherStore = $isTemp ? $this->permStore : $this->tempStore;

        // 双 Store 原子读-检查-写
        $errorResponse = null;
        $dedupEntry = null;
        $dedupCode = null;
        $coldFallback = (bool)($this->config['cold_fallback'] ?? false);
        $synced = false;
        $hotWriteAttempted = false;
        $mutationStarted = false;
        $operationLock = null;
        $urlLock = null;
        try {
            if (!$isTemp && !$isCustom) {
                $urlLock = new LinkOperationLock($this->permStore->getPath(), 'url:' . $url);
                if (!$urlLock->acquired()) {
                    return BizResponse::error('operation_in_progress', '该目标链接正在创建，请稍后重试', 409);
                }
            }
            $storePath = $store->getPath();
            $otherPath = $otherStore->getPath();
            $transactionResult = JsonStore::withBothLocks($this->permStore, $this->tempStore, function (&$allData) use (
                &$code, &$shortUrl, &$entry, &$errorResponse, &$dedupEntry, &$dedupCode,
                &$operationLock,
                $url, $ttl, $exp_str, $isTemp, $isCustom, $svcCodeLength, $isServiceKey, $domain, $storePath, $otherPath
            ) {
                $data = &$allData[$storePath];
                $otherData = &$allData[$otherPath];
                if ($isTemp) cleanExpiredEntries($data);
                if (!$isTemp && !$isCustom) {
                    foreach ($data as $existingCode => $existing) {
                        if (isset($existing['url'], $existing['lurl']) && $existing['url'] === $url) {
                            $code = $existingCode;
                            $operationLock = new LinkOperationLock($this->permStore->getPath(), 'code:' . $code);
                            if (!$operationLock->acquired()) {
                                $errorResponse = BizResponse::error('operation_in_progress', '该短码正在操作，请稍后重试', 409);
                                return ['write' => [], 'result' => false];
                            }
                            $dedupCode = $existingCode;
                            $dedupEntry = $existing;
                            return ['write' => [], 'result' => true];
                        }
                    }
                }
                if ($isCustom) {
                    if (isset($data[$code]) || isset($otherData[$code])) {
                        $errorResponse = BizResponse::error('conflict', '已占用', 409);
                    }
                } else {
                    $chars = '23456789abcdefghjkmnpqrstuvwxyz';
                    $maxLen = strlen($chars) - 1;
                    $generated = false;
                    for ($i = 0; $i < 8; $i++) {
                        $candidate = '';
                        for ($j = 0; $j < $svcCodeLength; $j++) $candidate .= $chars[random_int(0, $maxLen)];
                        if (in_array($candidate, $this->config['reserved_codes'])) continue;
                        if (isset($data[$candidate]) || isset($otherData[$candidate])) continue;
                        $code = $candidate;
                        $generated = true;
                        break;
                    }
                    if (!$generated) {
                        $errorResponse = BizResponse::error('gen_failed', '无法生成后缀', 500);
                    } else {
                        $shortUrl = $domain . '/' . $code;
                        $entry['id'] = $code;
                        $entry['lurl'] = $shortUrl;
                    }
                }
                if (!$errorResponse) {
                    $activeCount = 0;
                    foreach ($data as $item) {
                        if (!isExpired($item['t'] ?? null)) $activeCount++;
                    }
                    $limit = $isServiceKey
                        ? ($this->config[$isTemp ? 'svc_temp_limit' : 'svc_perm_limit'] ?? 9999)
                        : $this->config[$isTemp ? 'temp_limit' : 'perm_limit'];
                    if ($activeCount >= $limit) {
                        $errorResponse = BizResponse::error('quota_exceeded', '已达上限', 429);
                    }
                }
                if (!$errorResponse) {
                    $operationLock = new LinkOperationLock($this->permStore->getPath(), 'code:' . $code);
                    if (!$operationLock->acquired()) {
                        $errorResponse = BizResponse::error('operation_in_progress', '该短码正在操作，请稍后重试', 409);
                        return ['write' => [], 'result' => false];
                    }
                    return ['write' => [], 'result' => true];
                }
                return ['write' => [], 'result' => false];
            });
            if ($errorResponse) return $errorResponse;

            $mutationStarted = $this->internalApi->beginMutation($code);
            if (!$mutationStarted && !$coldFallback) {
                return BizResponse::error('hot_store_unavailable', '热存储暂不可用，请稍后重试', 503);
            }
            if (!$mutationStarted) error_log("[create] mutation 标记未确认，已启用冷存储兜底: code={$code}");
            $hotWriteAttempted = $dedupEntry === null;
            $remainingTtl = $isTemp ? max(1, $now + $ttl - time()) : 0;
            $synced = $this->internalApi->setWithRetry($code, $url, $remainingTtl, $exp_str);
            if (!$synced && !$coldFallback) {
                if ($hotWriteAttempted) $this->internalApi->deleteWithRetry($code, $isTemp ? 'temp' : 'perm');
                return BizResponse::error('hot_store_unavailable', '热存储暂不可用，请稍后重试', 503);
            }
            if ($dedupEntry === null) {
                // 其他短码可在热请求期间提交；必须重读并重新检查配额，不能覆盖旧快照。
                JsonStore::withBothLocks($this->permStore, $this->tempStore, function (&$allData) use (
                    $storePath, $otherPath, $code, $entry, $isTemp, $isServiceKey, &$errorResponse
                ) {
                    $data = &$allData[$storePath];
                    if ($isTemp) cleanExpiredEntries($data);
                    if (isset($data[$code]) || isset($allData[$otherPath][$code])) {
                        $errorResponse = BizResponse::error('conflict', '已占用', 409);
                    } else {
                        $active = count(array_filter($data, fn($item) => !isExpired($item['t'] ?? null)));
                        $limit = $isServiceKey
                            ? ($this->config[$isTemp ? 'svc_temp_limit' : 'svc_perm_limit'] ?? 9999)
                            : $this->config[$isTemp ? 'temp_limit' : 'perm_limit'];
                        if ($active >= $limit) $errorResponse = BizResponse::error('quota_exceeded', '已达上限', 429);
                    }
                    if ($errorResponse) return ['write' => [], 'result' => false];
                    $data[$code] = $entry;
                    return ['write' => [$storePath], 'result' => true];
                });
                if ($errorResponse) $this->internalApi->deleteWithRetry($code, $isTemp ? 'temp' : 'perm');
            }
        } catch (\Throwable $e) {
            error_log('[create] ' . $e->getMessage());
            if ($hotWriteAttempted) {
                $this->internalApi->deleteWithRetry($code, $isTemp ? 'temp' : 'perm');
            }
            return BizResponse::error('write_failed', '服务器内部错误', 500);
        } finally {
            if ($mutationStarted) {
                $this->internalApi->endMutation($code);
            }
            if ($operationLock) $operationLock->release();
            if ($urlLock) $urlLock->release();
        }

        if ($errorResponse) {
            return $errorResponse;
        }

        if (!$synced) {
            error_log("[create] 冷存储记录已保留，热同步未确认，等待后台对账: code={$code}");
        }
        $warning = $synced ? null : '短链已保存到冷存储，但未确认热存储同步，可能暂时无法访问，等待后台对账恢复。';

        if ($dedupEntry !== null && $dedupCode !== null) {
            return BizResponse::success([
                'short_url' => $dedupEntry['lurl'],
                'exp' => null,
                'dedup' => true,
                'synced' => $synced,
                'warning' => $warning,
                'key_consumed' => $auth->consumed,
            ]);
        }

        return BizResponse::success([
            'short_url'    => $shortUrl,
            'exp'          => $isTemp ? $exp_str : null,
            'dedup'        => false,
            'synced'       => $synced,
            'warning'      => $warning,
            'key_consumed' => $auth->consumed,
        ]);
    }
}
