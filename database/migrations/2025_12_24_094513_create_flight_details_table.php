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
        Schema::create('flight_details', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('flight_id')->constrained('flights')->onDelete('cascade')->unique();
            
            $table->dateTime('arrival_datetime')->nullable();
      
            $table->string('aircraft_code', 10)->nullable();

            $table->string('aircraft_type_code', 10)->nullable();
                        
            $table->timestamp('last_updated_at')->nullable();


        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flight_details');
    }
};
