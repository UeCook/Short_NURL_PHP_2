<?php
/**
 * JsonStore — JSON 文件读写（安全写入）
 *
 * 数据格式：{"v":1, "at":"ISO8601", "d":{"code": {entry}, ...}}
 * 所有写入通过 .php.tmp + 原子 rename 完成
 */

namespace ShortURL\Core;

class JsonStore
{
    private string $path;
    private string $tz_offset;
    private $lockFp = null;

    public function getPath(): string
    {
        return $this->path;
    }

    public function __construct(string $path, string $tz_offset = '+08:00')
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new \RuntimeException("无法创建目录: {$dir}");
        }
        $this->path = $path;
        $this->tz_offset = $tz_offset;
    }

    /**
     * 读取 JSON 文件，返回 'd' 数据对象
     */
    public function read(): array
    {
        if (!file_exists($this->path)) {
            return [];
        }
        $raw = @file_get_contents($this->path);
        if ($raw === false) {
            throw new \RuntimeException("数据文件读取失败: " . $this->path);
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['d']) || !is_array($data['d'])) {
            throw new \RuntimeException("数据文件 JSON 损坏: " . $this->path);
        }
        return $data['d'];
    }

    /**
     * 安全写入 JSON 文件
     */
    public function write(array $data): void
    {
        $this->lockBegin();
        try {
            $this->writeLocked($data);
        } finally {
            $this->lockEnd();
        }
    }

    /**
     * 按短码查找条目
     */
    public function find(string $code): ?array
    {
        $code = strtolower($code);
        $data = $this->read();
        return isset($data[$code]) ? $data[$code] : null;
    }

    /**
     * 统计总条目数
     */
    public function count(): int
    {
        return count($this->read());
    }

    /**
     * 统计有效（未过期）条目数
     */
    public function countActive(): int
    {
        $count = 0;
        foreach ($this->read() as $code => $item) {
            if (!isExpired($item['t'] ?? null)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * 获取排他文件锁
     */
    public function lockBegin(): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new \RuntimeException("无法创建目录: {$dir}");
        }
        $this->lockFp = @fopen($this->path . '.lock', 'c+');
        if (!$this->lockFp) {
            throw new \RuntimeException("无法打开锁文件: " . $this->path . '.lock');
        }
        if (!flock($this->lockFp, LOCK_EX)) {
            fclose($this->lockFp);
            $this->lockFp = null;
            throw new \RuntimeException("无法获取排他锁: " . $this->path . '.lock');
        }
    }

    /**
     * 在持有排他锁的状态下读取数据
     */
    public function readLocked(): array
    {
        if (!$this->lockFp) {
            throw new \RuntimeException("未持有锁，请先调用 lockBegin()");
        }

        if (!file_exists($this->path)) {
            return [];
        }

        $raw = @file_get_contents($this->path);
        if ($raw === false) {
            throw new \RuntimeException("数据文件读取失败: " . $this->path);
        }
        if (trim($raw) === '') {
            throw new \RuntimeException("数据文件为空: " . $this->path);
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['d']) || !is_array($data['d'])) {
            throw new \RuntimeException("数据文件 JSON 损坏: " . $this->path);
        }
        return $data['d'];
    }

    /**
     * 在持有排他锁的状态下安全写入数据
     */
    public function writeLocked(array $data): void
    {
        if (!$this->lockFp) {
            throw new \RuntimeException("未持有锁，请先调用 lockBegin()");
        }
        $envelope = [
            'v'  => 1,
            'at' => formatIso8601(time(), $this->tz_offset),
            'd'  => $data,
        ];
        try {
            $json = json_encode(
                $envelope,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $e) {
            throw new \RuntimeException('数据 JSON 编码失败', 0, $e);
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

        // 写入 tmp 文件 + rename 原子替换
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
     * 释放排他文件锁
     */
    public function lockEnd(): void
    {
        if ($this->lockFp) {
            flock($this->lockFp, LOCK_UN);
            fclose($this->lockFp);
            $this->lockFp = null;
        }
    }

    /**
     * 同时锁定两个 Store，并以路径顺序执行回调
     */
    public static function withBothLocks(JsonStore $first, JsonStore $second, callable $callback)
    {
        $stores = [$first, $second];
        usort($stores, function (JsonStore $a, JsonStore $b) {
            return strcmp($a->getPath(), $b->getPath());
        });

        $stores[0]->lockBegin();
        try {
            $stores[1]->lockBegin();
            try {
                $data = [
                    $stores[0]->getPath() => $stores[0]->readLocked(),
                    $stores[1]->getPath() => $stores[1]->readLocked(),
                ];
                $result = $callback($data);
                if (!is_array($result) || !isset($result['write']) || !is_array($result['write'])) {
                    throw new \RuntimeException('双 Store 事务回调必须返回写入路径');
                }
                foreach ($result['write'] as $path) {
                    if ($path === $stores[0]->getPath()) {
                        $stores[0]->writeLocked($data[$path]);
                    } elseif ($path === $stores[1]->getPath()) {
                        $stores[1]->writeLocked($data[$path]);
                    } else {
                        throw new \RuntimeException('双 Store 事务返回了未知路径');
                    }
                }
                return $result['result'] ?? null;
            } finally {
                $stores[1]->lockEnd();
            }
        } finally {
            $stores[0]->lockEnd();
        }
    }
}
