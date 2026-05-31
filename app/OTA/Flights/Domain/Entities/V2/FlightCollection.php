<?php

namespace App\Services\OTA\Flights\Domain\Entities\V2;

final class FlightCollection implements \IteratorAggregate, \Countable
{
    /** @var Flight[] */
    private array $flights = [];

    public function add(Flight $flight): void
    {
        $this->flights[] = $flight;
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->flights);
    }

    public function count(): int
    {
        return count($this->flights);
    }

    public function toArray(): array
    {
        return $this->flights;
    }

    public function isEmpty(): bool
    {
        return empty($this->flights);
    }
}