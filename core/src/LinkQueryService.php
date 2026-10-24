<?php
/**
 * LinkQueryService — 查询服务（list/stat/get）
 */

namespace ShortURL\Core;

class LinkQueryService
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
     * 列出所有短链
     *
     * @param AuthContext $auth 认证上下文
     * @return BizResponse
     */
    public function list(AuthContext $auth): BizResponse
    {
        // 一次性密钥禁止进行短链管理
        if ($auth->keyType === 'onetime') {
            return BizResponse::error('onetime_forbidden', '一次性密钥无权进行短链管理', 403);
        }

        try {
            $permData = $this->permStore->read();
            $tempData = $this->tempStore->read();
        } catch (\RuntimeException $e) {
            error_log('[list] 冷存储读取失败: ' . $e->getMessage());
            return BizResponse::error('data_corrupted', '数据文件损坏', 500);
        }

        $list = [];

        foreach ($permData as $code => $item) {
            $list[] = [
                'id'   => $item['id'] ?? $code,
                'url'  => $item['url'] ?? '',
                'lurl' => $item['lurl'] ?? '',
                'exp'  => 'permanent',
            ];
        }

        foreach ($tempData as $code => $item) {
            if (isExpired($item['t'] ?? null)) {
                continue;
            }
            $list[] = [
                'id'   => $item['id'] ?? $code,
                'url'  => $item['url'] ?? '',
                'lurl' => $item['lurl'] ?? '',
                'exp'  => $item['t'] ?? null,
            ];
        }

        return BizResponse::success($list);
    }

    /** 将扁平列表转换为 v1 WebUI 使用的分组结构。 */
    public function listGrouped(AuthContext $auth): BizResponse
    {
        $result = $this->list($auth);
        if (!$result->ok) return $result;

        $grouped = ['permanent' => [], 'temporary' => []];
        foreach ($result->data as $item) {
            $grouped[$item['exp'] === 'permanent' ? 'permanent' : 'temporary'][] = $item;
        }
        return BizResponse::success($grouped);
    }

    /**
     * 获取配额状态
     *
     * @param AuthContext $auth 认证上下文
     * @return BizResponse
     */
    public function stat(AuthContext $auth): BizResponse
    {
        // 一次性密钥禁止进行短链管理
        if ($auth->keyType === 'onetime') {
            return BizResponse::error('onetime_forbidden', '一次性密钥无权进行短链管理', 403);
        }

        try {
            $permActive = $this->permStore->countActive();
            $tempActive = $this->tempStore->countActive();
        } catch (\RuntimeException $e) {
            error_log('[stat] 冷存储读取失败: ' . $e->getMessage());
            return BizResponse::error('data_corrupted', '数据文件损坏', 500);
        }

        $isServiceKey = $auth->keyType === 'service';
        $permLimit = $isServiceKey
            ? ($this->config['svc_perm_limit'] ?? 18000)
            : ($this->config['perm_limit'] ?? 10000);
        $tempLimit = $isServiceKey
            ? ($this->config['svc_temp_limit'] ?? 1500)
            : ($this->config['temp_limit'] ?? 500);

        // 获取热存储计数（仅供诊断）
        $hotStats = $this->internalApi->stat();
        $hotPermCount = $hotStats['perm_count'] ?? null;
        $hotTempCount = $hotStats['temp_count'] ?? null;
        if ($hotStats === null) {
            error_log("[stat] 热存储不可用，使用冷存储计数：cold_perm={$permActive}, cold_temp={$tempActive}");
        } elseif ((int)$hotPermCount !== $permActive || (int)$hotTempCount !== $tempActive) {
            error_log("[stat] 热冷计数漂移: hot perm={$hotPermCount} cold perm={$permActive}, hot temp={$hotTempCount} cold temp={$tempActive}");
        }

        return BizResponse::success([
            'perm_count'      => $permActive,
            'perm_limit'      => $permLimit,
            'temp_count'      => $tempActive,
            'temp_limit'      => $tempLimit,
            'hot_perm_count'  => $hotPermCount,
            'hot_temp_count'  => $hotTempCount,
            'hot_available'   => $hotStats !== null,
        ]);
    }

    /**
     * 获取单条短链
     *
     * @param AuthContext $auth 认证上下文
     * @param string $code 短码
     * @return BizResponse
     */
    public function get(AuthContext $auth, string $code): BizResponse
    {
        // 一次性密钥禁止进行短链管理
        if ($auth->keyType === 'onetime') {
            return BizResponse::error('onetime_forbidden', '一次性密钥无权进行短链管理', 403);
        }

        if (empty($code)) {
            return BizResponse::error('missing_code', '缺少短链后缀');
        }
        $code = strtolower($code);

        // 查永久存储
        try {
            $permData = $this->permStore->read();
        } catch (\RuntimeException $e) {
            error_log('[get] 冷存储读取失败: ' . $e->getMessage());
            return BizResponse::error('data_corrupted', '数据文件损坏', 500);
        }
        if (isset($permData[$code])) {
            $item = $permData[$code];
            if (!isset($item['id'], $item['url'], $item['lurl'])) {
                return BizResponse::error('data_corrupted', '数据条目损坏', 500);
            }
            return BizResponse::success([
                'id'   => $item['id'],
                'url'  => $item['url'],
                'lurl' => $item['lurl'],
                'exp'  => 'permanent',
            ]);
        }

        // 查临时存储
        try {
            $tempData = $this->tempStore->read();
        } catch (\RuntimeException $e) {
            error_log('[get] 冷存储读取失败: ' . $e->getMessage());
            return BizResponse::error('data_corrupted', '数据文件损坏', 500);
        }
        if (isset($tempData[$code])) {
            $item = $tempData[$code];
            if (!isset($item['id'], $item['url'], $item['lurl'])) {
                return BizResponse::error('data_corrupted', '数据条目损坏', 500);
            }
            if (isExpired($item['t'] ?? null)) {
                return BizResponse::error('expired', '该短链已过期', 404);
            }
            return BizResponse::success([
                'id'   => $item['id'],
                'url'  => $item['url'],
                'lurl' => $item['lurl'],
                'exp'  => $item['t'] ?? null,
            ]);
        }

        return BizResponse::error('not_found', '该短链不存在', 404);
    }
}
