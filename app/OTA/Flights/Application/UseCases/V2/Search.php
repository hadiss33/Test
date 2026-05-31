<?php

namespace App\Services\OTA\Flights\Application\UseCases\V2;

use App\Services\OTA\Flights\Application\Contracts\V2\SearchInterface;
use App\Services\OTA\Flights\Application\DTOs\V2\Request;
use App\Services\OTA\Flights\Application\DTOs\V2\Response;
use App\Services\OTA\Flights\Domain\Repositories\V2\RepositoryInterface;

final class Search implements SearchInterface
{
    public function __construct(
        private RepositoryInterface $repository,
    ) {}

    public function search(Request $request): Response
    {
        $flights = $this->repository->searchByRouteAndDate([
            'request' => $request,
        ]);

        return new Response(
            status: !$flights->isEmpty(),
            time: time(),
            currencyCode: 'IRR',
            flights: $flights,
            rawResult: [], // توسط repository پر می‌شه
        );
    }
}