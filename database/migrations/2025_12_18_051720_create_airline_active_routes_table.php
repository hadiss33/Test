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
            $table->bigInteger('application_interfaces_id');

            $table->foreign('application_interfaces_id')
                ->references('id')->on('application_interfaces')->onDelete('cascade');

            $table->boolean('monday')->nullable();
            $table->boolean('tuesday')->nullable();
            $table->boolean('wednesday')->nullable();
            $table->boolean('thursday')->nullable();
            $table->boolean('friday')->nullable();
            $table->boolean('saturday')->nullable();
            $table->boolean('sunday')->nullable();
            $table->tinyInteger('priority')->nullable();
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
