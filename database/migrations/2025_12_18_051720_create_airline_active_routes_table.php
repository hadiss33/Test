<?php

/**
 * ========================================
 * PART 1: MIGRATIONS
 * ========================================
 */

/**
 * Migration 1: create_airline_active_routes_table
 * php artisan make:migration create_airline_active_routes_table
 */
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('airline_active_routes', function (Blueprint $table) {
            $table->id();
            $table->string('iata');
            $table->string('origin');
            $table->string('destination');
            $table->string('service');
            
            $table->boolean('monday')->default(false);
            $table->boolean('tuesday')->default(false);
            $table->boolean('wednesday')->default(false);
            $table->boolean('thursday')->default(false);
            $table->boolean('friday')->default(false);
            $table->boolean('saturday')->default(false);
            $table->boolean('sunday')->default(false);
            
            $table->timestamp('updated_at')->nullable();
            
            $table->index(['iata', 'origin', 'destination']);
            $table->unique(['iata', 'origin', 'destination']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('airline_active_routes');
    }
};