<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_class_id')
                ->constrained('flight_classes')
                ->onDelete('cascade');
            
            $table->enum('passenger_type', ['adult', 'child', 'infant']);
            $table->string('tax_code', 10);
            $table->decimal('tax_amount', 12, 2)->nullable();             
            $table->string('title_en')->nullable(); 
            $table->string('title_fa')->nullable(); 

            $table->unique(['flight_class_id', 'passenger_type', 'tax_code'], 'unique_tax');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taxes');
    }
};