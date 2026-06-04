<?php

namespace App\Services\OTA\Flights\Infrastructure\Providers\Sepehr\V2\Auth;

final readonly class SepehrCredential
{
    public function __construct(
        public int    $id,
        public int    $supplierObjectId,    // object column در application_interface
        public int    $branch,
        public string $url,
        public string $username,
        public string $hashedPassword,     // md5 از password اصلی
        public bool   $hasNira,
        public bool   $isHub,
    ) {}

    public function buildParams(string $method): array
    {
        if (\App\Services\OTA\Flights\Infrastructure\Providers\Sepehr\V2\Config\SepehrConfig::usesCredentialWrapper($method)) {
            return [
                'Credential' => [
                    'Username' => $this->username,
                    'Password' => $this->hashedPassword,
                ]
            ];
        }
        return [
            'Username' => $this->username,
            'Password' => $this->hashedPassword,
        ];
    }
}