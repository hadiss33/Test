<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Jobs\{
    CleanupOldFlightsJob,
    UpdateFlightsPriorityJob,
    CheckMissingFlightsJob,
    SyncAirlineRoutesJob
};

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        $schedule->job(new SyncAirlineRoutesJob('nira'))
            ->dailyAt('00:00')
            ->name('sync-airline-routes')
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->job(new CleanupOldFlightsJob)
            ->dailyAt('01:00')
            ->name('cleanup-old-flights')
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->job(new CheckMissingFlightsJob)
            ->everyTenMinutes()
            ->name('check-missing-flights')
            ->withoutOverlapping()
            ->onOneServer();


        $schedule->job(new UpdateFlightsPriorityJob(3, 'nira'))
            ->everyTwoMinutes()
            ->name('update-flights-period-3')
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->job(new UpdateFlightsPriorityJob(7, 'nira'))
            ->everyFiveMinutes()
            ->name('update-flights-period-7')
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->job(new UpdateFlightsPriorityJob(30, 'nira'))
            ->everyFifteenMinutes()
            ->name('update-flights-period-30')
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->job(new UpdateFlightsPriorityJob(60, 'nira'))
            ->everyThirtyMinutes()
            ->name('update-flights-period-60')
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->job(new UpdateFlightsPriorityJob(90, 'nira'))
            ->hourly()
            ->name('update-flights-period-90')
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->job(new UpdateFlightsPriorityJob(120, 'nira'))
            ->everyThreeHours()
            ->name('update-flights-period-120')
            ->withoutOverlapping()
            ->onOneServer();
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');
        require base_path('routes/console.php');
    }
}