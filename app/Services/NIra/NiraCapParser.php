<?php

namespace App\Services\Nira;

class NiraCapParser
{
    /**
     * Parse کردن Cap field نیرا
     *
     * فرمت‌های ممکن:
     * "CPC"  → کلاس CP، بسته (C آخر)
     * "CQ5"  → کلاس CQ، ۵ صندلی باقیمانده
     * "PC"   → کلاس P،  بسته
     * "SC"   → کلاس S،  بسته
     * "TC"   → کلاس T،  بسته
     *
     * قانون:
     * - اگه آخرین کاراکتر C باشه → بسته → capacity = 0
     * - اگه آخرین کاراکتر عدد باشه → اون عدد = صندلی
     */
    public static function parse(string $cap, string $flightClass): array
    {
        $cap = trim($cap);

        if (empty($cap)) {
            return ['is_open' => false, 'capacity' => 0];
        }

        $lastChar = substr($cap, -1);

        // بسته
        if ($lastChar === 'C') {
            return ['is_open' => false, 'capacity' => 0];
        }

        // عدد → باز
        if (is_numeric($lastChar)) {
            // اگه کل cap عدد باشه
            if (is_numeric($cap)) {
                return ['is_open' => true, 'capacity' => (int) $cap];
            }

            // Cap مثل "CQ5" → عدد آخر رو بگیر
            preg_match('/(\d+)$/', $cap, $matches);
            $capacity = isset($matches[1]) ? (int) $matches[1] : 1;

            return ['is_open' => true, 'capacity' => $capacity];
        }

        // حالت ناشناخته → محافظه‌کارانه بسته در نظر بگیر
        return ['is_open' => false, 'capacity' => 0];
    }

    /**
     * تحلیل تمام کلاس‌های یه پرواز و برگردوندن summary
     */
    public static function analyzeClasses(array $classesStatus): array
    {
        $openClasses   = 0;
        $minPrice      = PHP_INT_MAX;
        $minCapacity   = PHP_INT_MAX;
        $totalCapacity = 0;

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
            }
        }

        return [
            'open_class_count' => $openClasses,
            'min_price'        => $minPrice === PHP_INT_MAX ? 0 : $minPrice,
            'min_capacity'     => $minCapacity === PHP_INT_MAX ? 0 : $minCapacity,
            'total_capacity'   => $totalCapacity,
        ];
    }
}