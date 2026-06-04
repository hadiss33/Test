<?php

namespace App\Services\OTA\Flights\Domain\Repositories;

interface ActiveRouteRepositoryInterface
{
    /**
     * Check if an active route exists for a supplier on a given day.
     */
    public function findRoute(int $supplierId, int $originId, int $destinationId, string $dayOfWeek): ?object;

    /**
     * Upsert active routes from provider sync.
     */
    public function upsert(array $data): void;
}