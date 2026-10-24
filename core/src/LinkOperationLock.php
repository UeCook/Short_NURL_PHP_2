<?php
namespace ShortURL\Core;

/** 同一短码/目标的操作串行；锁文件保留，避免 unlink 后不同进程锁住不同 inode。 */
class LinkOperationLock
{
    private $fp;

    public function __construct(string $storePath, string $identity)
    {
        $dir = dirname($storePath) . '/.operations';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('无法创建操作锁目录');
        }
        $this->fp = @fopen($dir . '/' . hash('sha256', $identity) . '.lock', 'c+');
        if (!$this->fp) throw new \RuntimeException('无法打开操作锁');
        if (!flock($this->fp, LOCK_EX | LOCK_NB)) {
            fclose($this->fp);
            $this->fp = null;
        }
    }

    public function acquired(): bool { return $this->fp !== null; }

    public function release(): void
    {
        if ($this->fp) {
            flock($this->fp, LOCK_UN);
            fclose($this->fp);
            $this->fp = null;
        }
    }

    public function __destruct() { $this->release(); }
}
