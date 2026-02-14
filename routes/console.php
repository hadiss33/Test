<?php
use Illuminate\Support\Facades\Schedule;
use App\Jobs\{
    CleanupOldFlightsJob,
    UpdateFlightsPriorityJob,
    CheckMissingFlightsJob,
    SyncAirlineRoutesJob
};

$airlines = ['nira', 'sepehr' , 'ravis'];

/*
|--------------------------------------------------------------------------
| Sync airline routes
|--------------------------------------------------------------------------
*/
foreach ($airlines as $airline) {
    Schedule::job(new SyncAirlineRoutesJob($airline))
        ->dailyAt('00:00')
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


$periods = [
    3  => 'everyTwoMinutes',
    7  => 'everyFiveMinutes',
    30 => 'everyFifteenMinutes',
    60 => 'everyThirtyMinutes',
    90 => 'hourly',
    120 => 'everyThreeHours',
];

foreach ($airlines as $airline) {
    foreach ($periods as $period => $frequency) {
        Schedule::job(new UpdateFlightsPriorityJob($period, $airline))
            ->{$frequency}()
            ->name("update-flights:$airline:$period")
            ->withoutOverlapping()
            ->onOneServer();
    }
}
