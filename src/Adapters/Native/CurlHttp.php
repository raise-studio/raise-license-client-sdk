<?php

namespace RaiseStudio\License\Adapters\Native;

use RaiseStudio\License\Contracts\HttpClientInterface;
use RaiseStudio\License\Contracts\HttpClientResponse;

/**
 * 原生 cURL HTTP 适配器
 *
 * 适用场景：无框架的裸 PHP 项目。
 *
 * 使用方式：
 *   $http = new CurlHttp();
 *   $client = new LicenseClient($productCode, $publicKey, $cache, $http, ...);
 */
class CurlHttp implements HttpClientInterface
{
    public function post(string $url, array $data, int $timeout): HttpClientResponse
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($data),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_HEADER         => false,
            // 不验证 SSL（与 Laravel Http 默认行为一致）
            // 生产环境建议保留验证
        ]);

        $body = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return new HttpClientResponse(
                statusCode: 0,
                jsonBody: null,
                headers: [],
            );
        }

        $jsonBody = json_decode($body, true);

        return new HttpClientResponse(
            statusCode: $statusCode,
            jsonBody: $jsonBody !== false ? $jsonBody : null,
            headers: [],
        );
    }
}
