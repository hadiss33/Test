<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Jobs\{CleanupOldFlightsJob, UpdateFlightsPriorityJob, CheckMissingFlightsJob};

class Kernel extends ConsoleKernel
{

    protected function schedule(Schedule $schedule): void
    {
         $schedule->job(new CleanupOldFlightsJob)
            ->dailyAt('00:00')
            ->name('cleanup-old-flights')
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->job(new CheckMissingFlightsJob)
            ->everyTenMinutes()
            ->name('check-missing-flights')
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->job(new UpdateFlightsPriorityJob(1, 'nira'))
            ->everyMinute()
            ->name('update-flights-priority-1')
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->job(new UpdateFlightsPriorityJob(2, 'nira'))
            ->everyThreeMinutes()
            ->name('update-flights-priority-2')
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->job(new UpdateFlightsPriorityJob(3, 'nira'))
            ->everyFiveMinutes()
            ->name('update-flights-priority-3')
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->job(new UpdateFlightsPriorityJob(4, 'nira'))
            ->everyTwentyMinutes()
            ->name('update-flights-priority-4')
            ->withoutOverlapping()
            ->onOneServer();
    }


    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}