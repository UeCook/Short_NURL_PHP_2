<?php
/**
 * AuthContext — 可信进程内值对象
 *
 * 由 Auth 生成并传入业务服务的不可变值对象。
 * 只在同一可信 PHP 请求调用栈中使用，不跨 HTTP、CLI、队列或持久化边界。
 */

namespace ShortURL\Core;

final class AuthContext
{
    public function __construct(
        public readonly string $keyType,    // resident / onetime / service
        public readonly bool $consumed,     // 是否为已预约、成功后将消费的 onetime
        public readonly ?string $keyPrefix, // KEY 前缀（用于日志）
        public readonly ?string $reservationToken = null,
    ) {}
}
