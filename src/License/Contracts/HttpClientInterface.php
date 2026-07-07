<?php

namespace RaiseStudio\License\Contracts;

/**
 * HTTP 客户端抽象接口
 *
 * SDK 不绑定任何具体 HTTP 实现，调用方通过此接口注入自己的 HTTP 客户端。
 *
 * 各平台适配：
 * - Laravel:    Illuminate\Support\Facades\Http
 * - WordPress:  wp_remote_post()
 * - Native PHP: cURL
 */
interface HttpClientInterface
{
    /**
     * 发送 POST 请求
     *
     * @param string $url     请求地址
     * @param array  $data    请求体数据
     * @param int    $timeout 超时时间（秒）
     * @return HttpClientResponse
     */
    public function post(string $url, array $data, int $timeout): HttpClientResponse;
}
