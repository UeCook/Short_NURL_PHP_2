<?php
/**
 * InternalApi — PHP→Lua 通信
 *
 * 端点：/internal/set、/internal/delete、/internal/stat
 * 仅 127.0.0.1 可访问（Nginx 层强制）
 */

namespace ShortURL\Core;

class InternalApi
{
    private array $config;
    private ?array $lastError = null;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * 每次调用从文件读取 LPA-Key 令牌
     */
    private function readInternalToken(): string
    {
        $path = $this->config['internal_token_path'] ?? '';
        if ($path === '') return '';
        $raw = @file_get_contents($path);
        return $raw !== false ? trim($raw) : '';
    }

    /**
     * 构建内部请求的 LPA-Key 头
     */
    private function buildHeaders(): array
    {
        $token = $this->readInternalToken();
        return $token !== '' ? ['LPA-Key: ' . $token] : [];
    }

    /**
     * 获取最近一次内部请求的错误信息
     */
    public function getLastError(): ?array
    {
        return $this->lastError;
    }

    /**
     * 写入热存储
     *
     * @param string $code 短码
     * @param string $url 原始链接
     * @param int $ttl TTL 秒数（0 = 永久）
     * @param string $expStr ISO 8601 过期时间字符串（永久为 "0"）
     * @return array|null
     */
    public function set(string $code, string $url, int $ttl, string $expStr): ?array
    {
        return $this->post('/internal/set', [
            'code'    => $code,
            'url'     => $url,
            'ttl'     => $ttl,
            'exp_str' => $expStr,
        ]);
    }

    /**
     * 带重试的写入热存储
     */
    public function setWithRetry(string $code, string $url, int $ttl, string $expStr, int $maxRetries = 3): bool
    {
        for ($i = 0; $i < $maxRetries; $i++) {
            $result = $this->set($code, $url, $ttl, $expStr);
            if ($result !== null) return true;
            if ($i < $maxRetries - 1) {
                usleep(100000 * pow(2, $i));
            }
        }
        return false;
    }

    /**
     * 删除热存储
     */
    public function delete(string $code, string $type = ''): ?array
    {
        $params = ['code' => $code];
        if ($type !== '') {
            $params['type'] = $type;
        }
        return $this->post('/internal/delete', $params);
    }

    /** 带重试地从热存储删除。 */
    public function deleteWithRetry(string $code, string $type = '', int $maxRetries = 3): bool
    {
        for ($i = 0; $i < $maxRetries; $i++) {
            if ($this->delete($code, $type) !== null) return true;
            if ($i < $maxRetries - 1) {
                usleep(100000 * (2 ** $i));
            }
        }
        return false;
    }

    public function beginMutation(string $code): bool
    {
        return $this->post('/internal/mutation', ['code' => $code, 'action' => 'begin']) !== null;
    }

    public function endMutation(string $code): void
    {
        $this->post('/internal/mutation', ['code' => $code, 'action' => 'end']);
    }

    /**
     * 读取热存储计数
     */
    public function stat(): ?array
    {
        $this->lastError = null;

        $host = $this->config['internal_host'];
        $timeout = $this->config['internal_timeout'];
        $url = $host . '/internal/stat';

        $ch = curl_init($url);
        if ($ch === false) {
            $this->lastError = ['type' => 'curl', 'message' => 'cURL 初始化失败', 'http_code' => 0];
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $this->buildHeaders(),
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            $this->lastError = ['type' => 'curl', 'message' => "cURL 错误：{$err}", 'http_code' => 0];
            return null;
        }
        if ($code === 200 && $resp) {
            if ($contentType && strpos($contentType, 'application/json') === false) {
                $this->lastError = ['type' => 'content_type', 'message' => "非 JSON 响应：{$contentType}", 'http_code' => $code];
                return null;
            }
            $d = json_decode($resp, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->lastError = ['type' => 'json', 'message' => '响应 JSON 解析失败：' . json_last_error_msg(), 'http_code' => $code];
                return null;
            }
            if (!is_array($d) || !isset($d['perm_count'], $d['temp_count'])) {
                $this->lastError = ['type' => 'response', 'message' => '内部统计响应结构无效', 'http_code' => $code];
                return null;
            }
            return $d;
        }
        $this->lastError = ['type' => 'http', 'message' => "HTTP {$code}", 'http_code' => $code];
        return null;
    }

    /**
     * 内部 POST 请求
     */
    private function post(string $path, array $params = []): ?array
    {
        $this->lastError = null;

        try {
            $requestBody = json_encode($params, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->lastError = ['type' => 'json', 'message' => '内部请求编码失败', 'http_code' => 0];
            return null;
        }

        $host = $this->config['internal_host'];
        $timeout = $this->config['internal_timeout'];
        $url = $host . $path;

        $ch = curl_init($url);
        if ($ch === false) {
            $this->lastError = ['type' => 'curl', 'message' => 'cURL 初始化失败', 'http_code' => 0];
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $requestBody,
            CURLOPT_HTTPHEADER => array_merge(
                ['Content-Type: application/json'],
                $this->buildHeaders()
            ),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            $this->lastError = ['type' => 'curl', 'message' => "cURL 错误：{$err}", 'http_code' => 0];
            return null;
        }
        if ($code === 200 && $resp) {
            if ($contentType && strpos($contentType, 'application/json') === false) {
                $this->lastError = ['type' => 'content_type', 'message' => "非 JSON 响应：{$contentType}", 'http_code' => $code];
                return null;
            }
            $d = json_decode($resp, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->lastError = ['type' => 'json', 'message' => '响应 JSON 解析失败：' . json_last_error_msg(), 'http_code' => $code];
                return null;
            }
            if (!is_array($d) || ($d['ok'] ?? false) !== true) {
                $this->lastError = ['type' => 'response', 'message' => '内部接口未确认操作成功', 'http_code' => $code];
                return null;
            }
            return $d;
        }
        $reason = '';
        if ($code === 403) $reason = '（IP 不在 allow 列表）';
        elseif ($code === 500) $reason = '（OpenResty 内部错误）';
        $this->lastError = ['type' => 'http', 'message' => "HTTP {$code}{$reason}", 'http_code' => $code];
        return null;
    }
}
