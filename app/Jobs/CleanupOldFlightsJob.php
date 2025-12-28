<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use App\Services\FlightCleanupService;
use Illuminate\Support\Facades\Log;


class CleanupOldFlightsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(FlightCleanupService $cleanupService): void
    {
        Log::info('Starting cleanup of old flights...');
        
        $result = $cleanupService->cleanupPastFlights();
        
        Log::info('Cleanup completed', $result);
    }
}