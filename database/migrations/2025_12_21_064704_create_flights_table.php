<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('flights', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('airline_active_route_id')->constrained('airline_active_routes')->onDelete('cascade');
            $table->string('flight_number', 20);

            $table->dateTime('departure_datetime');
            $table->date('flight_date')->index();
            $table->string('flight_class', 10)->default('Y');
            $table->string('class_status', 10)->nullable();
            $table->string('aircraft_type', 10)->nullable();
            
            $table->decimal('price_adult', 12, 2)->default(0);
            $table->decimal('price_child', 12, 2)->default(0);
            $table->decimal('price_infant', 12, 2)->default(0);

            $table->integer('available_seats')->default(0);
            $table->enum('status', ['active', 'full', 'cancelled', 'closed'])->default('active');
            $table->tinyInteger('update_priority')->default(3);
            $table->timestamp('last_updated_at')->nullable();
            
            $table->json('raw_data')->nullable();

            $table->index(['status', 'available_seats']);
            $table->index('update_priority');
            $table->index('departure_datetime');
            $table->unique(
                ['airline_active_route_id', 'flight_number', 'flight_class', 'flight_date'], 'unique_flight_per_day'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flights');
    }
};
