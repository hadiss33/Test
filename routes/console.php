<?php

use Illuminate\Support\Facades\Schedule;
use App\Jobs\CleanupOldFlightsJob;
use App\Jobs\UpdateFlightsPriorityJob;
use App\Jobs\CheckMissingFlightsJob;
use App\Jobs\SyncAirlineRoutesJob;


$airlines = ['nira', 'sepehr', 'ravis'];

$periods = [
    3   => 'everyTwoMinutes',
    7   => 'everyFiveMinutes',
    30  => 'everyFifteenMinutes',
    60  => 'everyThirtyMinutes',
    90  => 'hourly',
    120 => 'everyThreeHours',
];


foreach ($airlines as $airline) {
    Schedule::job(new SyncAirlineRoutesJob($airline))
        ->dailyAt('07:32')
        ->name("sync-airline-routes:$airline")
        ->withoutOverlapping()
        ->onOneServer();
}

Schedule::job(new CleanupOldFlightsJob)
    ->dailyAt('01:00')
    ->name('cleanup-old-flights')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::job(new CheckMissingFlightsJob)
    ->everyTenMinutes()
    ->name('check-missing-flights')
    ->withoutOverlapping()
    ->onOneServer();

foreach ($airlines as $airline) {
    foreach ($periods as $period => $frequency) {
        Schedule::job(new UpdateFlightsPriorityJob($period, $airline))
            ->{$frequency}()
            ->name("update-flights:$airline:$period")
            ->withoutOverlapping()
            ->onOneServer();
    }
}
