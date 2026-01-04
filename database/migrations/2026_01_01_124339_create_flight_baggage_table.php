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
        Schema::create('flight_baggage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_class_id')
                ->constrained('flight_classes')
                ->onDelete('cascade');
            

            $table->integer('adult_weight')->nullable(); 
            $table->integer('adult_pieces')->nullable();
            $table->integer('infant_weight')->nullable(); 
            $table->integer('infant_pieces')->nullable();
            $table->integer('child_weight')->nullable(); 
            $table->integer('child_pieces')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flight_baggage');
    }
};
