<?php

namespace App\Services\Nira;

use Carbon\Carbon;

class NiraFlightScorer
{

    public static function calculate(
        int    $openClassCount,
        int    $minCapacity,
        int    $minPrice,
        Carbon $departureDateTime
    ): array {
        if ($openClassCount === 0) {
            return [
                'flight_score' => 0,
                'next_check_at' => null,
            ];
        }

        $hoursToDepart = now()->diffInHours($departureDateTime, false);

        if ($hoursToDepart < 2) {
            return [
                'flight_score' => 0,
                'next_check_at' => null,
            ];
        }


        $classScore = min(30, $openClassCount * 5);

        $capacityScore = $minCapacity <= 1  ? 30
                       : ($minCapacity <= 3  ? 25
                       : ($minCapacity <= 5  ? 15
                       : ($minCapacity <= 10 ? 5
                       : 0)));

        $timeScore = $hoursToDepart <= 6    ? 40
                   : ($hoursToDepart <= 12   ? 35
                   : ($hoursToDepart <= 24   ? 30
                   : ($hoursToDepart <= 48   ? 20
                   : ($hoursToDepart <= 72   ? 15
                   : ($hoursToDepart <= 168  ? 10
                   : ($hoursToDepart <= 720  ? 5   
                   : 2))))));                    
        $score = min(100, $classScore + $capacityScore + $timeScore);

        $intervalMinutes = self::scoreToInterval($score, $hoursToDepart);

        $intervalMinutes = self::adjustForTimeOfDay($intervalMinutes);

        return [
            'flight_score' => $score,
            'next_check_at' => now()->addMinutes($intervalMinutes),
        ];
    }


    private static function scoreToInterval(int $score, int $hoursToDepart): int
    {
        if ($hoursToDepart <= 12) {
            return $score >= 50 ? 2
                 : ($score >= 30 ? 5
                 : 15);
        }

        return $score >= 80 ? 2    // P1
             : ($score >= 60 ? 5    // P2
             : ($score >= 40 ? 15   // P3
             : ($score >= 25 ? 30   // P4
             : ($score >= 10 ? 60   // P5
             : 180))));             // P6 - 3 ساعت
    }


    private static function adjustForTimeOfDay(int $intervalMinutes): int
    {
        $hour = now()->setTimezone('Asia/Tehran')->hour;

        $multiplier = match (true) {
            $hour >= 0  && $hour < 6  => 3.0,  // خلوت شبانه
            $hour >= 9  && $hour < 13 => 0.7,  // اوج صبح
            $hour >= 17 && $hour < 23 => 0.7,  // اوج عصر
            default                   => 1.0,  // نرمال
        };

        return (int) max(2, $intervalMinutes * $multiplier);
    }
}