<?php

namespace App\Services\Nira;

use Carbon\Carbon;

class NiraFlightScorer
{
    /**
     * محاسبه score و next_check_at برای یه پرواز
     *
     * Score تعیین می‌کنه:
     * - پرواز چقدر ارزش update داره
     * - چه موقع باید دوباره چک بشه
     *
     * عوامل:
     * - open_class_count : تعداد کلاس‌های باز
     * - min_capacity     : کمترین ظرفیت (داره پر می‌شه؟)
     * - hours_to_depart  : چند ساعت تا پرواز
     */
    public static function calculate(
        int    $openClassCount,
        int    $minCapacity,
        int    $minPrice,
        Carbon $departureDateTime
    ): array {
        // پرواز بسته → هیچ ارزشی نداره
        if ($openClassCount === 0) {
            return [
                'flight_score' => 0,
                'next_check_at' => null, // FlightStatusSyncJob نگهش می‌بینه
            ];
        }

        $hoursToDepart = now()->diffInHours($departureDateTime, false);

        // پرواز گذشته یا خیلی نزدیک (< 2 ساعت)
        if ($hoursToDepart < 2) {
            return [
                'flight_score' => 0,
                'next_check_at' => null,
            ];
        }

        // --- محاسبه امتیاز ---

        // ۱. امتیاز تعداد کلاس باز (0-30)
        $classScore = min(30, $openClassCount * 5);

        // ۲. امتیاز ظرفیت کم (0-30) → هرچه ظرفیت کمتر، امتیاز بیشتر
        // ظرفیت ۱ → امتیاز ۳۰ | ظرفیت ۱۰+ → امتیاز ۰
        $capacityScore = $minCapacity <= 1  ? 30
                       : ($minCapacity <= 3  ? 25
                       : ($minCapacity <= 5  ? 15
                       : ($minCapacity <= 10 ? 5
                       : 0)));

        // ۳. امتیاز زمانی (0-40)
        $timeScore = $hoursToDepart <= 6    ? 40
                   : ($hoursToDepart <= 12   ? 35
                   : ($hoursToDepart <= 24   ? 30
                   : ($hoursToDepart <= 48   ? 20
                   : ($hoursToDepart <= 72   ? 15
                   : ($hoursToDepart <= 168  ? 10  // 7 روز
                   : ($hoursToDepart <= 720  ? 5   // 30 روز
                   : 2))))));                       // 31-120 روز

        $score = min(100, $classScore + $capacityScore + $timeScore);

        // --- محاسبه next_check_at ---
        $intervalMinutes = self::scoreToInterval($score, $hoursToDepart);

        // تنظیم بر اساس ساعت روز (ایران)
        $intervalMinutes = self::adjustForTimeOfDay($intervalMinutes);

        return [
            'flight_score' => $score,
            'next_check_at' => now()->addMinutes($intervalMinutes),
        ];
    }

    /**
     * تبدیل score به interval (دقیقه)
     */
    private static function scoreToInterval(int $score, int $hoursToDepart): int
    {
        // پرواز‌های خیلی نزدیک حتی با score پایین باید چک بشن
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

    /**
     * تنظیم interval بر اساس ساعت روز
     * ساعت خلوت (00:00-06:00) → interval × 3
     * ساعت اوج (09:00-13:00 و 17:00-23:00) → interval × 0.7
     */
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