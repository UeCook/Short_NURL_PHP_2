<?php
/**
 * KeyStore — API Key 管理（SHA-256 哈希，纯 PHP Base58）
 *
 * 两种 Key 类型：
 *   - 常驻 Key（Resident）：7 天有效期，不限使用次数
 *   - 一次性 Key 池（Onetime Pool）：用前永不过期，使用后自动销毁
 *   - 服务 Key（Service）：永不过期，不限使用次数
 *
 * v2 变更：verify 接受 allowedTypes，在同一把锁内完成匹配、类型检查和预约
 */

namespace ShortURL\Core;

class KeyStore
{
    private string $path;
    private string $tz_offset;
    private int $ttlDays;
    private int $poolSize;

    public function __construct(string $path, string $tz_offset = '+08:00', int $ttlDays = 7, int $poolSize = 20)
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new \RuntimeException("无法创建目录: {$dir}");
        }
        $this->path = $path;
        $this->tz_offset = $tz_offset;
        $this->ttlDays = $ttlDays;
        $this->poolSize = $poolSize;
    }

    /**
     * 生成原始密钥
     */
    private function generateRawKey(): string
    {
        $bytes = random_bytes(32);
        $encoded = base58_encode($bytes);
        return 'su_' . $encoded;
    }

    /**
     * 读取 keys.json 文件
     */
    private function readFile(): array
    {
        if (!file_exists($this->path)) {
            return ['resident' => null, 'onetime_pool' => []];
        }
        $raw = @file_get_contents($this->path);
        if ($raw === false) {
            throw new \RuntimeException("keys.json 文件读取失败: " . $this->path);
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException("keys.json 数据损坏: " . $this->path);
        }
        if (!isset($data['onetime_pool']) || !is_array($data['onetime_pool'])) {
            $data['onetime_pool'] = [];
        }
        if (!isset($data['service']) || !is_array($data['service'])) {
            $data['service'] = null;
        }
        return $data;
    }

    /**
     * 安全写入 keys 数据
     */
    private function writeLockedFile(array $data): void
    {
        try {
            $json = json_encode(
                $data,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $e) {
            throw new \RuntimeException('keys.json 编码失败', 0, $e);
        }
        $bak = $this->path . '.bak';
        $tmp = $this->path . '.php.tmp';

        // 备份当前文件
        if (file_exists($this->path)) {
            $currentContent = @file_get_contents($this->path);
            if ($currentContent !== false && strlen($currentContent) > 0) {
                if (@file_put_contents($bak, $currentContent, LOCK_EX) === false) {
                    throw new \RuntimeException("备份失败：$bak");
                }
            }
        }

        // tmp写入 + rename 原子替换
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            @unlink($tmp);
            throw new \RuntimeException("写入临时文件失败：$tmp");
        }
        if (!@rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new \RuntimeException("rename 失败：$tmp → {$this->path}");
        }

        // 验证写入完整性
        $verify = @file_get_contents($this->path);
        if ($verify === false || json_decode($verify, true) === null) {
            if (file_exists($bak) && ($bakContent = @file_get_contents($bak)) !== false) {
                $rollbackWritten = @file_put_contents($tmp, $bakContent, LOCK_EX);
                $rollbackRenamed = $rollbackWritten !== false && @rename($tmp, $this->path);
                if ($rollbackRenamed) {
                    throw new \RuntimeException("写入验证失败，已回滚：{$this->path}");
                }
                @unlink($tmp);
            }
            throw new \RuntimeException("写入验证失败且回滚失败：{$this->path}");
        }
    }

    /**
     * 验证原始 Key 是否与存储的哈希匹配
     *
     * v2 变更：在同一把锁内完成匹配、类型检查和 onetime 预约
     *
     * @param string $rawKey 请求中传入的 Key
     * @param array $allowedTypes 允许的 KEY 类型
     * @return KeyVerifyResult
     */
    public function verify(string $rawKey, array $allowedTypes): KeyVerifyResult
    {
        if (empty($rawKey)) return KeyVerifyResult::invalid();
        if (!file_exists($this->path)) return KeyVerifyResult::invalid();

        $hash = auth_hash($rawKey);
        $now = time();

        $lockFp = @fopen($this->path . '.lock', 'c+');
        if (!$lockFp) {
            error_log("[key_verify] 无法打开锁文件：{$this->path}.lock");
            return KeyVerifyResult::backendError();
        }
        if (!flock($lockFp, LOCK_EX)) {
            fclose($lockFp);
            error_log("[key_verify] 无法获取排他锁：{$this->path}.lock");
            return KeyVerifyResult::backendError();
        }
        try {
            $now = time(); // 获取锁可能等待，预约时限从实际验证时刻计算。
            $raw = @file_get_contents($this->path);
            if ($raw === false) {
                error_log("[key_verify] 文件读取失败：{$this->path}");
                return KeyVerifyResult::backendError();
            }
            $keys = json_decode($raw, true);
            if (!is_array($keys)) {
                error_log("[key_verify] keys.json 解码失败：{$this->path}");
                return KeyVerifyResult::backendError();
            }
            if (!isset($keys['onetime_pool']) || !is_array($keys['onetime_pool'])) {
                $keys['onetime_pool'] = [];
            }
            if ($this->reapReservations($keys, $now)) $this->writeLockedFile($keys);

            // 检查常驻 Key
            if (isset($keys['resident']) && is_array($keys['resident'])) {
                $slot = $keys['resident'];
                if (isset($slot['key_hash']) && is_string($slot['key_hash']) && hash_equals($slot['key_hash'], $hash)) {
                    if (!in_array('resident', $allowedTypes)) {
                        return KeyVerifyResult::wrongChannel();
                    }
                    $expiresStr = $slot['expires'] ?? null;
                    if (!is_string($expiresStr) || $expiresStr === '') {
                        error_log("[key_verify] resident 缺少 expires 字段：{$this->path}");
                        return KeyVerifyResult::invalid();
                    }
                    $expiresTs = strtotime($expiresStr);
                    if ($expiresTs !== false && $expiresTs > $now) {
                        return KeyVerifyResult::success('resident');
                    }
                    error_log("[key_verify] resident 已过期：{$this->path}");
                    return KeyVerifyResult::invalid();
                }
            }

            // 检查服务 Key
            if (isset($keys['service']) && is_array($keys['service'])) {
                $slot = $keys['service'];
                if (isset($slot['key_hash']) && is_string($slot['key_hash']) && hash_equals($slot['key_hash'], $hash)) {
                    if (!in_array('service', $allowedTypes)) {
                        return KeyVerifyResult::wrongChannel();
                    }
                    return KeyVerifyResult::success('service');
                }
            }

            // 检查一次性 Key 池
            foreach ($keys['onetime_pool'] as $i => $slot) {
                if (isset($slot['key_hash']) && is_string($slot['key_hash']) && hash_equals($slot['key_hash'], $hash)) {
                    if (!in_array('onetime', $allowedTypes)) {
                        return KeyVerifyResult::wrongChannel();
                    }
                    $reservedAt = intval($slot['reserved_at'] ?? 0);
                    if ($reservedAt > 0) {
                        return KeyVerifyResult::invalid();
                    }
                    $reservationToken = bin2hex(random_bytes(16));
                    $keys['onetime_pool'][$i]['reserved_at'] = $now;
                    $keys['onetime_pool'][$i]['reservation_token'] = $reservationToken;
                    $this->writeLockedFile($keys);
                    return KeyVerifyResult::success('onetime', $reservationToken);
                }
            }

            return KeyVerifyResult::invalid();

        } catch (\RuntimeException $e) {
            error_log("[key_verify] 写入失败，Key 未消费: " . $e->getMessage());
            return KeyVerifyResult::backendError();
        } finally {
            flock($lockFp, LOCK_UN);
            fclose($lockFp);
        }
    }

    /** 完成一次性 Key 预约；成功时消费，失败时释放。 */
    public function completeOnetime(string $reservationToken, bool $success): bool
    {
        try {
            return $this->withLock(function (&$keys) use ($reservationToken, $success) {
                if (!isset($keys['onetime_pool']) || !is_array($keys['onetime_pool'])) return false;
                foreach ($keys['onetime_pool'] as $i => $slot) {
                    if (!isset($slot['reservation_token']) ||
                        !is_string($slot['reservation_token']) ||
                        !hash_equals($slot['reservation_token'], $reservationToken)) {
                        continue;
                    }
                    if ($success || time() - intval($slot['reserved_at'] ?? 0) >= 60) {
                        $keys['onetime_pool'][$i]['key_hash'] = null;
                    }
                    unset(
                        $keys['onetime_pool'][$i]['reserved_at'],
                        $keys['onetime_pool'][$i]['reservation_token']
                    );
                    $this->writeLockedFile($keys);
                    return true;
                }
                return false;
            });
        } catch (\RuntimeException $e) {
            error_log('[key_complete] ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 在独立 .lock 文件内执行回调
     */
    private function withLock(callable $callback)
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new \RuntimeException("无法创建目录: {$dir}");
        }
        $lockFp = @fopen($this->path . '.lock', 'c+');
        if (!$lockFp) throw new \RuntimeException("无法打开锁文件: " . $this->path . '.lock');
        if (!flock($lockFp, LOCK_EX)) {
            fclose($lockFp);
            throw new \RuntimeException("无法获取排他锁: " . $this->path . '.lock');
        }
        try {
            $raw = @file_get_contents($this->path);
            if ($raw === false) $raw = '';
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                if (!file_exists($this->path)) {
                    $data = ['resident' => null, 'onetime_pool' => []];
                } elseif ($raw === '' || $raw === null) {
                    throw new \RuntimeException("keys.json 文件为空");
                } else {
                    throw new \RuntimeException("keys.json 数据损坏");
                }
            }
            if (!isset($data['onetime_pool']) || !is_array($data['onetime_pool'])) {
                $data['onetime_pool'] = [];
            }
            if (!isset($data['service']) || !is_array($data['service'])) {
                $data['service'] = null;
            }
            $result = $callback($data);
            return $result;
        } finally {
            flock($lockFp, LOCK_UN);
            fclose($lockFp);
        }
    }

    /**
     * 生成新的常驻 Key
     */
    public function generateResident(): string
    {
        return $this->withLock(function(&$data) {
            $raw = $this->generateRawKey();
            $hash = auth_hash($raw);
            $prefix = substr($raw, 0, 8);
            $now = time();
            $expires = formatIso8601($now + ($this->ttlDays * 86400), $this->tz_offset);
            $created = formatIso8601($now, $this->tz_offset);

            $data['resident'] = [
                'key_hash'   => $hash,
                'key_prefix' => $prefix,
                'created'    => $created,
                'expires'    => $expires,
            ];
            $this->writeLockedFile($data);

            return $raw;
        });
    }

    /**
     * 将一次性 Key 池补充到最大容量
     */
    public function fillPool(): array
    {
        return $this->withLock(function(&$data) {
            $this->reapReservations($data, time());
            $pool = &$data['onetime_pool'];
            $newKeys = [];

            foreach ($pool as $i => $slot) {
                if (empty($slot['key_hash'])) {
                    $raw = $this->generateRawKey();
                    $hash = auth_hash($raw);
                    $prefix = substr($raw, 0, 8);
                    $created = formatIso8601(time(), $this->tz_offset);

                    $pool[$i] = [
                        'key_hash'   => $hash,
                        'key_prefix' => $prefix,
                        'created'    => $created,
                    ];
                    $newKeys[] = $raw;
                }
            }

            while (count($pool) < $this->poolSize) {
                $raw = $this->generateRawKey();
                $hash = auth_hash($raw);
                $prefix = substr($raw, 0, 8);
                $created = formatIso8601(time(), $this->tz_offset);

                $pool[] = [
                    'key_hash'   => $hash,
                    'key_prefix' => $prefix,
                    'created'    => $created,
                ];
                $newKeys[] = $raw;
            }

            $this->writeLockedFile($data);
            return $newKeys;
        });
    }

    /**
     * 生成新的服务密钥
     */
    public function generateService(string $label = 'default'): string
    {
        return $this->withLock(function(&$data) use ($label) {
            if (isset($data['service']) && is_array($data['service'])) {
                throw new \RuntimeException("服务密钥已存在，请先撤销旧密钥");
            }

            $raw = $this->generateRawKey();
            $hash = auth_hash($raw);
            $prefix = substr($raw, 0, 8);
            $created = formatIso8601(time(), $this->tz_offset);

            $data['service'] = [
                'key_hash'   => $hash,
                'key_prefix' => $prefix,
                'created'    => $created,
                'label'      => $label,
            ];
            $this->writeLockedFile($data);

            return $raw;
        });
    }

    /**
     * 吊销指定类型的 Key
     */
    public function revoke(string $type = 'all'): void
    {
        $this->withLock(function(&$data) use ($type) {
            if ($type === 'all') {
                $data['resident'] = null;
                $data['onetime_pool'] = [];
                $data['service'] = null;
            } elseif ($type === 'resident') {
                $data['resident'] = null;
            } elseif ($type === 'onetime') {
                $data['onetime_pool'] = [];
            } elseif ($type === 'service') {
                $data['service'] = null;
            } else {
                throw new \InvalidArgumentException("无效的吊销类型: {$type}");
            }
            $this->writeLockedFile($data);
        });
    }

    /**
     * 获取当前 Key 状态
     */
    public function status(): array
    {
        $keys = $this->withLock(function (&$data) {
            if ($this->reapReservations($data, time())) $this->writeLockedFile($data);
            return $data;
        });
        $result = [
            'resident' => null,
            'onetime_count' => 0,
            'onetime_reserved_count' => 0,
            'onetime_pool_size' => $this->poolSize,
            'onetime_pool' => [],
            'service' => null,
        ];

        if (isset($keys['resident']) && is_array($keys['resident'])) {
            $slot = $keys['resident'];
            $result['resident'] = [
                'prefix'  => $slot['key_prefix'] ?? '-',
                'created' => $slot['created'] ?? '-',
                'expires' => $slot['expires'] ?? '-',
            ];
        }

        if (isset($keys['service']) && is_array($keys['service'])) {
            $slot = $keys['service'];
            $result['service'] = [
                'prefix'  => $slot['key_prefix'] ?? '-',
                'created' => $slot['created'] ?? '-',
                'label'   => $slot['label'] ?? '-',
            ];
        }

        foreach ($keys['onetime_pool'] as $slot) {
            $isConsumed = empty($slot['key_hash']);
            $isReserved = !$isConsumed && intval($slot['reserved_at'] ?? 0) > 0;
            if ($isReserved) $result['onetime_reserved_count']++;
            if (!$isConsumed && !$isReserved) {
                $result['onetime_count']++;
            }
            $result['onetime_pool'][] = [
                'prefix'   => $slot['key_prefix'] ?? '-',
                'created'  => $slot['created'] ?? '-',
                'consumed' => $isConsumed,
                'reserved' => $isReserved,
            ];
        }

        return $result;
    }

    /** 超时预约可能已提交业务，永久作废旧 key，不能重新启用。调用方持锁。 */
    private function reapReservations(array &$keys, int $now): bool
    {
        $changed = false;
        foreach ($keys['onetime_pool'] as &$slot) {
            $at = intval($slot['reserved_at'] ?? 0);
            if ($at > 0 && $now - $at >= 60) {
                $slot['key_hash'] = null;
                unset($slot['reserved_at'], $slot['reservation_token']);
                $changed = true;
            }
        }
        unset($slot);
        return $changed;
    }
}
