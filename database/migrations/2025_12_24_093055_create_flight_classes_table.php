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
        Schema::create('flight_classes', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('flight_id')->constrained('flights')->onDelete('cascade');
            
            $table->string('class_code', 10); 
            
            $table->decimal('price_adult', 12, 2)->nullable();
            $table->decimal('price_child', 12, 2)->nullable();
            $table->decimal('price_infant', 12, 2)->nullable();
            
            $table->integer('available_seats')->nullable();
            $table->enum('status', ['active', 'full', 'closed', 'cancelled'])->default('active');
            
            $table->timestamp('last_updated_at')->nullable();
            
            $table->unique(['flight_id', 'class_code'], 'unique_flight_class');
            $table->index(['status', 'available_seats']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flight_classes');
    }
};
