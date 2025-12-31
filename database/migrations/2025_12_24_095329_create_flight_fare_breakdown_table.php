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
        Schema::create('flight_fare_breakdown', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('flight_class_id')
                ->constrained('flight_classes')
                ->onDelete('cascade');
            
            $table->enum('passenger_type', ['adult', 'child', 'infant']);
            
            $table->decimal('base_fare', 12, 2)->default(0);
            
            $table->decimal('tax_i6', 12, 2)->default(0);
            $table->decimal('tax_v0', 12, 2)->default(0); 
            $table->decimal('tax_hl', 12, 2)->default(0);
            $table->decimal('tax_lp', 12, 2)->default(0); 
            $table->decimal('tax_yq', 12, 2)->default(0);

            $table->decimal('total_price', 12, 2)->default(0);
            
            $table->timestamp('last_updated_at')->nullable();
            
            $table->unique(['flight_class_id', 'passenger_type'], 'unique_fare_breakdown');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flight_fare_breakdown');
    }
};
