<?php

namespace App\Services\OTA\Flights\Presentation\API\V2\Controllers;

use App\Services\OTA\Flights\Presentation\API\V2\Requests\SearchRequest;
use App\Services\OTA\Flights\Infrastructure\Providers\Sepehr\V2\SepehrApi;
use Illuminate\Http\JsonResponse;

class FlightController
{
    public function search(SearchRequest $request): JsonResponse
    {
        $data = $request->toArray();
        $details = $request->boolean('details', false);
        $branch = $request->input('branch', false);

        $result = SepehrApi::searchByRouteAndDate($data, $details, $branch);

        return response()->json($result);
    }
}