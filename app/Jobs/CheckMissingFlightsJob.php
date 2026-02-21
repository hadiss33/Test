<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use App\Services\FlightCleanupService;
// use Illuminate\Support\Facades\Log;


class CheckMissingFlightsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->queue = 'fastJob';
    }

    public function handle(\App\Services\FlightCleanupService $cleanupService): void
    {
        // Log::info('Checking for missing flights...');

        $result = $cleanupService->handleMissingFlights();

        // Log::info('Missing flights check completed', $result);
    }
}
