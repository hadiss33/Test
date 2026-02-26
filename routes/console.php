<?php

use Illuminate\Support\Facades\Schedule;
use App\Jobs\CleanupOldFlightsJob;
use App\Jobs\UpdateFlightsPriorityJob;
use App\Jobs\CheckMissingFlightsJob;
use App\Jobs\SyncAirlineRoutesJob;

$providersPeriods = [
    'nira' => [
        3   => 'everyThreeMinutes',
        7   => 'everyFifteenMinutes',
        30  => 'everyThirtyMinutes',
        60  => 'hourly',
        90  => 'everyThreeHours',
        120 => 'everySixHours',
    ],
    'sepehr' => [
        3   => 'everyTenMinutes',
        7   => 'everyFifteenMinutes',
        30  => 'everyThirtyMinutes',
        60  => 'hourly',
        90  => 'everyThreeHours',
        120 => 'everySixHours',
    ],
    'ravis' => [
        3   => 'everyTenMinutes',
        7   => 'everyFifteenMinutes',
        30  => 'everyThirtyMinutes',
        60  => 'hourly',
        90  => 'everyThreeHours',
        120 => 'everySixHours',
    ]
];

foreach ($providersPeriods as $provider => $periods) {
    Schedule::job(new SyncAirlineRoutesJob($provider))
        ->dailyAt('01:30')
        ->name("sync-airline-routes:$provider")
        ->withoutOverlapping()
        ->onOneServer();
}

Schedule::job(new CleanupOldFlightsJob)
    ->dailyAt('02:30')
    ->name('cleanup-old-flights')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::job(new CheckMissingFlightsJob)
    ->everyTenMinutes()
    ->name('check-missing-flights')
    ->withoutOverlapping()
    ->between('07:00', '23:59')
    ->onOneServer();

foreach ($providersPeriods as $provider => $periods) {
    foreach ($periods as $period => $frequency) {
        Schedule::job(new UpdateFlightsPriorityJob($period, $provider))
            ->{$frequency}()
            ->name("update-flights:$provider:$period")
            ->withoutOverlapping()
            ->between('07:00', '23:59')
            ->onOneServer();
    }
}

Schedule::command('queue:work --queue=snailJob --stop-when-empty --tries=3 --timeout=600')
    ->hourly()
    ->name('snail-queue-worker')
    ->withoutOverlapping()
    ->between('07:00', '23:59')
    ->onOneServer();
