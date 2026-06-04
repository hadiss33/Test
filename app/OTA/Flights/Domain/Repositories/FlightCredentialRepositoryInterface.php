<?php

namespace App\Services\OTA\Flights\Domain\Repositories;

interface FlightCredentialRepositoryInterface
{
    /**
     * Get all active API credentials for a branch.
     * Returns collection of credential objects.
     */
    public function getByBranch(int|string $branch, string $service): array;

    /**
     * Get a single credential by supplier object ID.
     */
    public function getBySupplier(int $supplierId, string $service): ?object;

    /**
     * Get hub (branch=1) credentials as fallback.
     */
    public function getHubCredentials(string $service): array;

    /**
     * Get base_online flag for a branch.
     */
    public function getBranchBaseOnline(int $branchId): ?int;
}