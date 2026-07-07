<?php

namespace RaiseStudio\License\Adapters\WordPress;

use RaiseStudio\License\Contracts\HttpClientInterface;
use RaiseStudio\License\Contracts\HttpClientResponse;

/**
 * WordPress HTTP 适配器
 *
 * 使用 wp_remote_post() 发送请求。
 *
 * 使用方式：
 *   $http = new WordPressHttp();
 *   $client = new LicenseClient($productCode, $publicKey, $cache, $http, ...);
 */
class WordPressHttp implements HttpClientInterface
{
    public function post(string $url, array $data, int $timeout): HttpClientResponse
    {
        $response = wp_remote_post($url, [
            'timeout' => $timeout,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
            'body' => json_encode($data),
        ]);

        if (is_wp_error($response)) {
            // 模拟网络异常：返回 0 状态码，调用方自行处理
            return new HttpClientResponse(
                statusCode: 0,
                jsonBody: null,
                headers: [],
            );
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $jsonBody = json_decode($body, true);
        $headerValue = wp_remote_retrieve_header($response, 'Retry-After');

        return new HttpClientResponse(
            statusCode: $statusCode,
            jsonBody: $jsonBody !== false ? $jsonBody : null,
            headers: $headerValue ? ['Retry-After' => $headerValue] : [],
        );
    }
}
