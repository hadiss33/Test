<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flight_taxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_class_id')
                ->constrained('flight_classes')
                ->onDelete('cascade');
            
            $table->enum('passenger_type', ['adult', 'child', 'infant']);
            $table->decimal('HL', 12, 2)->nullable();             
            $table->decimal('I6', 12, 2)->nullable();             
            $table->decimal('LP', 12, 2)->nullable();             
            $table->decimal('V0', 12, 2)->nullable();             
            $table->decimal('YQ', 12, 2)->nullable();             


            $table->unique(['flight_class_id', 'passenger_type'], 'unique_tax');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flight_taxes');
    }
};