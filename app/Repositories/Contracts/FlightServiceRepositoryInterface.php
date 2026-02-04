<?php

namespace App\Repositories\Contracts;

interface FlightServiceRepositoryInterface
{
    public function getActiveServices(string $serviceName): array;

    public function getServiceByCode(string $serviceName, string $code): ?array;

    public function getService(string $serviceName): ?array;

    public function isServiceActive(string $serviceName, string $code): bool;
}