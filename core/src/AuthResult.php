<?php
/**
 * AuthResult — 认证层返回的结果
 *
 * 成功时包含 AuthContext，失败时包含 reason。
 */

namespace ShortURL\Core;

final class AuthResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?AuthContext $authContext = null,
        public readonly ?string $reason = null, // missing / invalid / wrong_channel / backend_error
    ) {}

    public static function ok(AuthContext $authContext): self
    {
        return new self(true, $authContext, null);
    }

    public static function fail(string $reason): self
    {
        return new self(false, null, $reason);
    }
}
