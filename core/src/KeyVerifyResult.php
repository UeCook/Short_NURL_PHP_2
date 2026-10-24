<?php
/**
 * KeyVerifyResult — KeyStore::verify() 返回值
 *
 * 区分三种失败原因：
 *   - invalid: KEY 不存在或已过期
 *   - wrong_channel: KEY 类型不在 allowedTypes 中
 *   - backend_error: 锁/文件/JSON 异常
 */

namespace ShortURL\Core;

final class KeyVerifyResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly ?string $keyType = null,    // 成功时：resident / onetime / service
        public readonly ?string $error = null,      // 失败时：invalid / wrong_channel / backend_error
        public readonly ?string $reservationToken = null,
    ) {}

    public static function success(string $keyType, ?string $reservationToken = null): self
    {
        return new self(true, $keyType, null, $reservationToken);
    }

    public static function invalid(): self
    {
        return new self(false, null, 'invalid');
    }

    public static function wrongChannel(): self
    {
        return new self(false, null, 'wrong_channel');
    }

    public static function backendError(): self
    {
        return new self(false, null, 'backend_error');
    }
}
