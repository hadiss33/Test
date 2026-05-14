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
        Schema::create('flight_range_prices', function (Blueprint $table) {
            $table->id();
            $table->string('origin');
            $table->string('destination');
            $table->decimal('min', 12, 2)->nullable();
            $table->decimal('max', 12, 2)->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flight_range_prices');
    }
};
