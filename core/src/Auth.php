<?php
/**
 * Auth — 认证服务
 *
 * 职责：验证 KEY + 消耗 onetime + 返回 AuthContext
 * 不做：HTTP 凭证提取、CORS、JSON 输出、exit、业务权限判断
 */

namespace ShortURL\Core;

final class Auth
{
    public function __construct(
        private KeyStore $keyStore
    ) {}

    /**
     * 验证凭证并返回认证结果
     *
     * @param string $credential 原始凭证
     * @param array $allowedTypes 允许的 KEY 类型
     * @return AuthResult
     */
    public function authenticate(string $credential, array $allowedTypes): AuthResult
    {
        if ($credential === '') {
            return AuthResult::fail('missing');
        }

        $verifyResult = $this->keyStore->verify($credential, $allowedTypes);

        if ($verifyResult->ok) {
            $context = new AuthContext(
                keyType: $verifyResult->keyType,
                consumed: $verifyResult->keyType === 'onetime',
                keyPrefix: substr($credential, 0, 8),
                reservationToken: $verifyResult->reservationToken,
            );
            return AuthResult::ok($context);
        }

        return AuthResult::fail($verifyResult->error);
    }

    /** 提交或释放一次性 Key 预约。 */
    public function complete(AuthContext $context, bool $success): bool
    {
        if ($context->keyType !== 'onetime') return true;
        if ($context->reservationToken === null) return false;
        return $this->keyStore->completeOnetime($context->reservationToken, $success);
    }
}
