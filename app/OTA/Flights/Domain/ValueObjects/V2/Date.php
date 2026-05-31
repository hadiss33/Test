<?php

namespace App\Services\OTA\Flights\Domain\ValueObjects\V2;

use Carbon\Carbon;

final readonly class Date
{
    public function __construct(
        public string $value, // Y-m-d H:i format
    ) {}

    public static function fromGregorian(string $date): self
    {
        return new self($date);
    }

    public static function fromJalali(string $jalaliDate): self
    {
        $cleanDate = str_replace(['\\/', '/'], '-', $jalaliDate);
        $carbon = \Morilog\Jalali\Jalalian::fromFormat('Y-m-d', $cleanDate)->toCarbon();
        return new self($carbon->toDateTimeString());
    }

    public function toDateString(): string
    {
        return Carbon::parse($this->value)->toDateString();
    }

    public function toDateTimeString(): string
    {
        return $this->value;
    }

    public function toSepehrFormat(): string
    {
        return $this->value;
    }
}