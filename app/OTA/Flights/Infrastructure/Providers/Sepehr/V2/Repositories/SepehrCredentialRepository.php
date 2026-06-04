<?php

namespace App\Services\OTA\Flights\Infrastructure\Providers\Sepehr\V2\Repositories;

use App\Services\OTA\Flights\Domain\Repositories\FlightCredentialRepositoryInterface;
use App\Services\OTA\Flights\Infrastructure\Providers\Sepehr\V2\Auth\SepehrCredential;
use App\Services\OTA\Flights\Infrastructure\Providers\Sepehr\V2\Auth\SepehrCredentialFactory;
use Illuminate\Support\Facades\DB;

class SepehrCredentialRepository implements FlightCredentialRepositoryInterface
{
    public function __construct(
        private readonly SepehrCredentialFactory $factory,
    ) {}

    /** @return SepehrCredential[] */
    public function getByBranch(int|string $branch, string $service = 'sepehr'): array
    {
        $rows = DB::table('application_interface')
            ->where('object_type', 'colleague')
            ->where('branch', $branch)
            ->where('status', 1)
            ->where('type', 'api')
            ->where('service', $service)
            ->get();

        return $this->factory->fromRows($rows);
    }

    public function getBySupplier(int $supplierId, string $service = 'sepehr'): ?object
    {
        $row = DB::table('application_interface')
            ->where('object_type', 'colleague')
            ->where('object', $supplierId)
            ->where('status', 1)
            ->where('type', 'api')
            ->where('service', $service)
            ->first();

        return $row ? $this->factory->fromRow($row) : null;
    }

    /** @return SepehrCredential[] */
    public function getHubCredentials(string $service = 'sepehr'): array
    {
        $rows = DB::table('application_interface')
            ->where('branch', 1)
            ->where('object_type', 'colleague')
            ->where('status', 1)
            ->where('type', 'api')
            ->where('service', $service)
            ->get();

        return $this->factory->fromRows($rows);
    }

    public function getBranchBaseOnline(int $branchId): ?int
    {
        $row = DB::table('offices')
            ->select('base_online')
            ->where('id', $branchId)
            ->first();

        return $row?->base_online;
    }
}