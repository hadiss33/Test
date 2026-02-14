<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TestScheduleCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:schedule';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test command to verify scheduler is working';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $timestamp = now()->format('Y-m-d H:i:s');
        $message = "✓ Schedule test executed at {$timestamp}";

        // Log to Laravel log file
        Log::info($message);

        // Write to a dedicated test file for easy viewing
        $logFile = storage_path('logs/schedule-test.log');
        file_put_contents($logFile, $message . PHP_EOL, FILE_APPEND);

        $this->info($message);

        return Command::SUCCESS;
    }
}
