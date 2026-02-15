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
        Schema::table('flights', function (Blueprint $table) {
            $table->string('iata')->nullable();

        });
        Schema::table('airline_active_routes', function (Blueprint $table) {
            $table->dropColumn('iata');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flights', function (Blueprint $table) {
            $table->dropColumn('iata');

        });
        Schema::table('airline_active_routes', function (Blueprint $table) {
            $table->string('iata')->nullable();

        });
    }
};
