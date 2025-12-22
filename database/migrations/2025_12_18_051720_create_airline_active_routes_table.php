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
            $table->string('airline_code', 10);
            $table->string('origin');
            $table->string('destination');
            $table->string('service');
            
            $table->tinyInteger('monday')->default(0);
            $table->tinyInteger('tuesday')->default(0);
            $table->tinyInteger('wednesday')->default(0);
            $table->tinyInteger('thursday')->default(0);
            $table->tinyInteger('friday')->default(0);
            $table->tinyInteger('saturday')->default(0);
            $table->tinyInteger('sunday')->default(0);
            
            $table->timestamps();
            
            $table->index(['airline_code', 'origin', 'destination']);
            $table->unique(['airline_code', 'origin', 'destination']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('airline_active_routes');
    }
};