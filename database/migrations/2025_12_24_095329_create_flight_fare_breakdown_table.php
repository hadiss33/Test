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

            $table->decimal('base_adult', 12, 2)->nullable();
            $table->decimal('base_child', 12, 2)->nullable();
            $table->decimal('base_infant', 12, 2)->nullable();

            $table->timestamp('updated_at')->nullable();
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
