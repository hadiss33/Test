<?php

namespace App\Services\OTA\Flights\Infrastructure\Providers\Sepehr\V2;

use Illuminate\Support\Facades\Http;
use Throwable;

final class Client
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $username,
        private readonly string $password,
    ) {}

    public function post(string $endpoint, array $payload): array
    {
        $param = array_merge([
            'UserName' => $this->username,
            'Password' => md5($this->password),
        ], $payload);

        try {
            $response = Http::withHeaders([
                'Accept-Encoding' => 'gzip, deflate',
            ])->post($this->baseUrl . $endpoint, $param);

            return $response->json() ?? [];

        } catch (Throwable $e) {
            return [
                'ErrorMessage' => $e->getMessage(),
                'ExceptionType' => 'Exception',
            ];
        }
    }

    public function postWithCredential(string $endpoint, array $payload): array
    {
        $param = array_merge([
            'Credential' => [
                'UserName' => $this->username,
                'Password' => md5($this->password),
            ],
        ], $payload);

        try {
            $response = Http::withHeaders([
                'Accept-Encoding' => 'gzip, deflate',
            ])->post($this->baseUrl . $endpoint, $param);

            return $response->json() ?? [];

        } catch (Throwable $e) {
            return [
                'ErrorMessage' => $e->getMessage(),
                'ExceptionType' => 'Exception',
            ];
        }
    }
}