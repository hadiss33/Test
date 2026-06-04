<?php

namespace App\Services\OTA\Flights\Infrastructure\Providers\Sepehr\V2\Http;

use App\Services\OTA\Flights\Infrastructure\Providers\Sepehr\V2\Auth\SepehrCredential;
use App\Services\OTA\Flights\Infrastructure\Providers\Sepehr\V2\Config\SepehrConfig;
use App\Services\OTA\Flights\Domain\Exceptions\ProviderException;
use Illuminate\Support\Facades\Http;
use Throwable;

class SepehrHttpClient
{
    public function post(SepehrCredential $credential, string $method, array $data = []): array
    {
        $endpoint = SepehrConfig::getEndpoint($method);
        $params   = array_merge($credential->buildParams($method), $data);

        try {
            return Http::post($credential->url . $endpoint, $params)->throw()->json();
        } catch (Throwable $e) {
            throw new ProviderException(
                message:      $e->getMessage() . ' : خطایی رخ داده است. لطفا به واحد IT اطلاع دهید.',
                providerCode: '2001-' . $e->getCode(),
                previous:     $e,
            );
        }
    }

    /**
     * POST با timeout بالا - برای Book که ممکن است دیر جواب بدهد
     */
    public function postWithTimeout(SepehrCredential $credential, string $method, array $data = [], int $timeout = 60): array
    {
        $endpoint = SepehrConfig::getEndpoint($method);
        $params   = array_merge($credential->buildParams($method), $data);

        try {
            return Http::timeout($timeout)->post($credential->url . $endpoint, $params)->json();
        } catch (Throwable $e) {
            throw new ProviderException(
                message:      $e->getMessage() . ' : خطایی رخ داده است.',
                providerCode: '2002-' . $e->getCode(),
                previous:     $e,
            );
        }
    }

    public function get(SepehrCredential $credential, string $method, array $data = []): array
    {
        $endpoint = SepehrConfig::getEndpoint($method);
        $params   = array_merge($credential->buildParams($method), $data);

        try {
            return Http::get($credential->url . $endpoint, $params)->throw()->json();
        } catch (Throwable $e) {
            throw new ProviderException(
                message:      $e->getMessage() . ' : خطایی رخ داده است.',
                providerCode: '2003-' . $e->getCode(),
                previous:     $e,
            );
        }
    }
}