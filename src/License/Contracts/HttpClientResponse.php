<?php

namespace RaiseStudio\License\Contracts;

/**
 * HTTP 响应值对象
 *
 * 解耦 HTTP 客户端和 SDK 主体，避免依赖 Guzzle/Laravel Http/WordPress wp_remote 的具体实现。
 */
class HttpClientResponse
{
    /**
     * @param int         $statusCode HTTP 状态码
     * @param array|null  $jsonBody   已解析的 JSON body（null 表示非 JSON 响应）
     * @param array       $headers    响应头（key => value）
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly ?array $jsonBody,
        public readonly array $headers = [],
    ) {
    }

    /**
     * 请求是否成功（2xx）
     */
    public function successful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /**
     * 获取响应头值
     */
    public function header(string $name, string $default = ''): string
    {
        return $this->headers[$name] ?? $default;
    }

    /**
     * 获取 JSON body
     */
    public function json(): ?array
    {
        return $this->jsonBody;
    }
}
