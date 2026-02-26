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
            $table->dateTime('departure_datetime')->index();            
            $table->tinyInteger('missing_count')->nullable();
            $table->tinyInteger('missing_count')->nullable();
            $table->timestamp('updated_at')->nullable();
            
            $table->unique(['airline_active_route_id', 'flight_number', 'departure_datetime'], 'unique_flight');
            $table->index(['departure_datetime', 'flight_number']);
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
