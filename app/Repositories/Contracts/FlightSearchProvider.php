<?php

namespace App\Repositories\Contracts;

interface FlightSearchProvider
{
    public function supports(?string $service): bool;

    public function search(array $filters): \Illuminate\Support\Collection;
}
