<?php

use Illuminate\Support\Facades\Schedule;
use App\Jobs\{
    CleanupOldFlightsJob,
    UpdateFlightsPriorityJob,
    CheckMissingFlightsJob,
    SyncAirlineRoutesJob
};


Schedule::job(new SyncAirlineRoutesJob('nira'))
    ->dailyAt('00:00')
    ->name('sync-airline-routes')
    ->withoutOverlapping()
    ->onOneServer();

    Schedule::job(new SyncAirlineRoutesJob('sepehr'))
    ->dailyAt('00:00')
    ->name('sync-airline-routes')
    ->withoutOverlapping()
    ->onOneServer();

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

Schedule::job(new UpdateFlightsPriorityJob(3, 'nira'))
    ->everyTwoMinutes()
    ->name('update-flights-period-3')
    ->withoutOverlapping()
    ->onOneServer();

    Schedule::job(new UpdateFlightsPriorityJob(3, 'sepehr'))
    ->everyTwoMinutes()
    ->name('update-flights-period-3')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::job(new UpdateFlightsPriorityJob(7, 'nira'))
    ->everyFiveMinutes()
    ->name('update-flights-period-7')
    ->withoutOverlapping()
    ->onOneServer();

    Schedule::job(new UpdateFlightsPriorityJob(7, 'sepehr'))
    ->everyFiveMinutes()
    ->name('update-flights-period-7')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::job(new UpdateFlightsPriorityJob(30, 'nira'))
    ->everyFifteenMinutes()
    ->name('update-flights-period-30')
    ->withoutOverlapping()
    ->onOneServer();

    Schedule::job(new UpdateFlightsPriorityJob(30, 'sepehr'))
    ->everyFifteenMinutes()
    ->name('update-flights-period-30')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::job(new UpdateFlightsPriorityJob(60, 'nira'))
    ->everyThirtyMinutes()
    ->name('update-flights-period-60')
    ->withoutOverlapping()
    ->onOneServer();

    Schedule::job(new UpdateFlightsPriorityJob(60, 'sepehr'))
    ->everyThirtyMinutes()
    ->name('update-flights-period-60')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::job(new UpdateFlightsPriorityJob(90, 'nira'))
    ->hourly()
    ->name('update-flights-period-90')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::job(new UpdateFlightsPriorityJob(90, 'sepehr'))
    ->hourly()
    ->name('update-flights-period-90')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::job(new UpdateFlightsPriorityJob(120, 'nira'))
    ->everyThreeHours()
    ->name('update-flights-period-120')
    ->withoutOverlapping()
    ->onOneServer();

    Schedule::job(new UpdateFlightsPriorityJob(120, 'sepehr'))
    ->everyThreeHours()
    ->name('update-flights-period-120')
    ->withoutOverlapping()
    ->onOneServer();