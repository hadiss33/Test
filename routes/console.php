<?php
use App\Console\Commands\UpdateFlights;
use Illuminate\Support\Facades\Artisan;

Artisan::command('flights:update {service=nira} {--airline=} {--priority=}', function ($service = 'nira') {
    // فراخوانی کلاس کامند
    $this->call(UpdateFlights::class, [
        'service' => $service, 
        '--airline' => $this->option('airline'), 
        '--priority' => $this->option('priority')
    ]);
})->purpose('Update flights manually');