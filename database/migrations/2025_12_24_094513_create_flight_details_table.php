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
            
            $table->boolean('has_transit')->default(false);
            $table->string('transit_city', 3)->nullable();
            
            $table->string('operating_airline', 10)->nullable();
            $table->string('operating_flight_no', 20)->nullable();
            
            $table->text('refund_rules')->nullable();
            
            $table->string('baggage_weight', 20)->nullable(); 
            $table->string('baggage_pieces', 20)->nullable();
            
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
