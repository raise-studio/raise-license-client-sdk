<?php

namespace RaiseStudio\License\Adapters\Laravel;

use Illuminate\Support\Facades\Http;
use RaiseStudio\License\Contracts\HttpClientInterface;
use RaiseStudio\License\Contracts\HttpClientResponse;

/**
 * Laravel HTTP 适配器
 *
 * 直接委托给 Laravel Http Facade。
 *
 * 使用方式：
 *   $http = new LaravelHttp();
 *   $client = new LicenseClient($productCode, $publicKey, $cache, $http, ...);
 */
class LaravelHttp implements HttpClientInterface
{
    public function post(string $url, array $data, int $timeout): HttpClientResponse
    {
        $response = Http::timeout($timeout)->post($url, $data);

        return new HttpClientResponse(
            statusCode: $response->status(),
            jsonBody: $response->json(),
            headers: $response->headers(),
        );
    }
}
