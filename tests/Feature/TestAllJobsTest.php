<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Jobs\{
    SyncAirlineRoutesJob,
    UpdateFlightsPriorityJob,
    FetchFlightFareJob,
    CheckMissingFlightsJob,
    CleanupOldFlightsJob
};
use App\Models\{
    AirlineActiveRoute,
    Flight,
    FlightClass,
    FlightFareBreakdown,
    FlightTax,
    FlightBaggage,
    FlightRule
};
use Illuminate\Support\Facades\{Queue, Log};

class TestAllJobsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // تنظیم fake queue برای تست
        Queue::fake();
    }

    /**
     * تست 1: SyncAirlineRoutesJob
     */
    public function test_sync_airline_routes_job()
    {
        echo "\n🧪 Testing SyncAirlineRoutesJob...\n";

        // Dispatch job
        $job = new SyncAirlineRoutesJob('nira', 'NV');
        $job->handle(app(\App\Repositories\Contracts\FlightServiceRepositoryInterface::class));

        // بررسی نتیجه
        $routesCount = AirlineActiveRoute::where('iata', 'NV')->count();
        
        $this->assertGreaterThan(0, $routesCount, "Routes should be synced");
        
        echo "✅ Routes synced: {$routesCount}\n";
    }

    /**
     * تست 2: UpdateFlightsPriorityJob
     */
    public function test_update_flights_priority_job()
    {
        echo "\n🧪 Testing UpdateFlightsPriorityJob...\n";

        // ایجاد route تستی
        $this->createTestRoute();

        // Dispatch job
        $job = new UpdateFlightsPriorityJob(3, 'nira', 'NV');
        $job->handle();

        // بررسی نتیجه
        $flightsCount = Flight::count();
        
        $this->assertGreaterThan(0, $flightsCount, "Flights should be created");
        
        echo "✅ Flights created: {$flightsCount}\n";
    }

    /**
     * تست 3: FetchFlightFareJob
     */
    public function test_fetch_flight_fare_job()
    {
        echo "\n🧪 Testing FetchFlightFareJob...\n";

        // ایجاد FlightClass تستی
        $flightClass = $this->createTestFlightClass();

        // Dispatch job
        $job = new FetchFlightFareJob($flightClass);
        $job->handle(app(\App\Repositories\Contracts\FlightServiceRepositoryInterface::class));

        // بررسی نتایج
        $flightClass->refresh();
        
        $this->assertNotNull($flightClass->payable_child, "Child price should be set");
        $this->assertNotNull($flightClass->payable_infant, "Infant price should be set");
        
        // بررسی Fare Breakdown
        $fareBreakdown = FlightFareBreakdown::where('flight_class_id', $flightClass->id)->first();
        $this->assertNotNull($fareBreakdown, "Fare breakdown should exist");
        
        // بررسی Taxes
        $taxesCount = FlightTax::where('flight_class_id', $flightClass->id)->count();
        $this->assertGreaterThan(0, $taxesCount, "Taxes should be saved");
        
        // بررسی Baggage
        $baggage = FlightBaggage::where('flight_class_id', $flightClass->id)->first();
        $this->assertNotNull($baggage, "Baggage should exist");
        $this->assertIsInt($baggage->adult_weight, "Baggage weight should be integer");
        
        // بررسی Rules
        $rulesCount = FlightRule::where('flight_class_id', $flightClass->id)->count();
        $this->assertGreaterThan(0, $rulesCount, "Rules should be saved");
        
        echo "✅ Fare details fetched:\n";
        echo "   - Child price: {$flightClass->payable_child}\n";
        echo "   - Infant price: {$flightClass->payable_infant}\n";
        echo "   - Taxes: {$taxesCount} records\n";
        echo "   - Baggage weight: {$baggage->adult_weight}\n";
        echo "   - Rules: {$rulesCount} records\n";
    }

    /**
     * تست 4: CheckMissingFlightsJob
     */
    public function test_check_missing_flights_job()
    {
        echo "\n🧪 Testing CheckMissingFlightsJob...\n";

        // ایجاد پرواز تستی با missing_count
        $flight = $this->createTestFlight();
        $flight->update(['missing_count' => 1]);

        // Dispatch job
        $job = new CheckMissingFlightsJob();
        $cleanupService = app(\App\Services\FlightCleanupService::class);
        $job->handle($cleanupService);

        echo "✅ Missing flights check completed\n";
    }

    /**
     * تست 5: CleanupOldFlightsJob
     */
    public function test_cleanup_old_flights_job()
    {
        echo "\n🧪 Testing CleanupOldFlightsJob...\n";

        // ایجاد پرواز قدیمی
        $oldFlight = $this->createTestFlight();
        $oldFlight->update([
            'departure_datetime' => now()->subDays(2)
        ]);

        // Dispatch job
        $job = new CleanupOldFlightsJob();
        $cleanupService = app(\App\Services\FlightCleanupService::class);
        $job->handle($cleanupService);

        // بررسی حذف شدن
        $exists = Flight::where('id', $oldFlight->id)->exists();
        $this->assertFalse($exists, "Old flight should be deleted");

        echo "✅ Old flights cleaned up\n";
    }

    /**
     * Helper: ایجاد route تستی
     */
    protected function createTestRoute()
    {
        return AirlineActiveRoute::create([
            'iata' => 'NV',
            'origin' => 'THR',
            'destination' => 'MHD',
            'application_interfaces_id' => 1,
            'monday' => true,
            'tuesday' => true,
            'wednesday' => true,
            'thursday' => true,
            'friday' => true,
            'saturday' => true,
            'sunday' => true,
        ]);
    }

    /**
     * Helper: ایجاد flight تستی
     */
    protected function createTestFlight()
    {
        $route = $this->createTestRoute();

        return Flight::create([
            'airline_active_route_id' => $route->id,
            'flight_number' => '2631',
            'departure_datetime' => now()->addDays(5),
            'missing_count' => 0,
        ]);
    }

    /**
     * Helper: ایجاد flight class تستی
     */
    protected function createTestFlightClass()
    {
        $flight = $this->createTestFlight();

        return FlightClass::create([
            'flight_id' => $flight->id,
            'class_code' => 'Y',
            'payable_adult' => 5000000,
            'available_seats' => 9,
            'status' => 'active',
        ]);
    }
}