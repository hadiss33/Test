<?php

namespace App\Services\Nira;

class NiraCapParser
{
    public static function parse(string $cap, string $flightClass): array
    {
        $cap = trim($cap);

        if (empty($cap)) {
            return ['is_open' => false, 'capacity' => 0];
        }

        $lastChar = substr($cap, -1);

        if ($lastChar === 'C') {
            return ['is_open' => false, 'capacity' => 0];
        }

        if (is_numeric($lastChar)) {
            if (is_numeric($cap)) {
                return ['is_open' => true, 'capacity' => (int) $cap];
            }

            preg_match('/(\d+)$/', $cap, $matches);
            $capacity = isset($matches[1]) ? (int) $matches[1] : 1;

            return ['is_open' => true, 'capacity' => $capacity];
        }

        return ['is_open' => false, 'capacity' => 0];
    }

    public static function analyzeClasses(array $classesStatus): array
    {
        $openClasses = 0;
        $minPrice = PHP_INT_MAX;
        $minCapacity = PHP_INT_MAX;
        $totalCapacity = 0;
        $maxPrice = 0;

        foreach ($classesStatus as $class) {
            $parsed = self::parse($class['Cap'] ?? '', $class['FlightClass'] ?? '');

            if ($parsed['is_open']) {
                $openClasses++;
                $totalCapacity += $parsed['capacity'];

                if ($parsed['capacity'] < $minCapacity) {
                    $minCapacity = $parsed['capacity'];
                }

                $price = (int) ($class['Price'] ?? 0);
                if ($price > 0 && $price < $minPrice) {
                    $minPrice = $price;
                }

                if ($price > 0 && $price > $maxPrice) {
                    $maxPrice = $price;
                }
            }
        }

        return [
            'open_class_count' => $openClasses,
            'min_price' => $minPrice === PHP_INT_MAX ? 0 : $minPrice,
            'min_capacity' => $minCapacity === PHP_INT_MAX ? 0 : $minCapacity,
            'total_capacity' => $totalCapacity,
            'max_price' => $maxPrice,
        ];
    }
}
