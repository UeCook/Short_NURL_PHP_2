<?php
/**
 * BizResponse — 业务层返回的标准响应
 *
 * 统一的业务响应格式，包含成功和失败两种情况。
 */

namespace ShortURL\Core;

final class BizResponse
{
    public function __construct(
        public readonly bool $ok,
        public readonly ?array $data = null,
        public readonly ?string $code = null,       // 英文错误代码
        public readonly ?string $message = null,    // 中文错误描述
        public readonly ?int $httpStatus = null,    // 建议的 HTTP 状态码
    ) {}

    public static function success(?array $data = null): self
    {
        return new self(true, $data, null, null, null);
    }

    public static function error(string $code, string $message, int $httpStatus = 400): self
    {
        return new self(false, null, $code, $message, $httpStatus);
    }
}
