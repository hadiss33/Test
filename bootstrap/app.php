<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

// Project uses Laravel 12
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->group('api', [
            'auth.jwt' => \App\Http\Middleware\AuthWithJwt::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->withSchedule(function (Illuminate\Console\Scheduling\Schedule $schedule) {
        $schedule->command('test:schedule')->everyMinute();
        $schedule->command('cache:clear-expired')->everyFiveMinutes();

        $schedule->command('queue:work --queue=fastJob,queueJob,snailJob --sleep=3 --tries=3 --timeout=600 --stop-when-empty')
            ->withoutOverlapping()
            ->runInBackground()
            ->everyMinute();

        $schedule->command('cache:backup-delete-flights')->dailyAt('00:00');
    })
    ->create();
