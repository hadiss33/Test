<?php

namespace App\Services\OTA\Flights\Application\Contracts\V2;

use App\Services\OTA\Flights\Application\DTOs\V2\Request;
use App\Services\OTA\Flights\Application\DTOs\V2\Response;

interface SearchInterface
{
    public function search(Request $request): Response;
}