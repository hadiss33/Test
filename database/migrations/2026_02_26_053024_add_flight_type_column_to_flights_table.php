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
            $table->boolean('is_open')->default(true)->after('flight_number')->index();
            $table->tinyInteger('open_class_count')->default(0)->after('is_open');
            $table->bigInteger('min_price')->default(0)->after('open_class_count');
            $table->tinyInteger('min_capacity')->default(0)->after('min_price');
            $table->tinyInteger('flight_score')->default(0)->after('min_capacity')->index();
            $table->timestamp('next_check_at')->nullable()->after('flight_score')->index();
            $table->timestamp('status_checked_at')->nullable()->after('next_check_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flights', function (Blueprint $table) {
            Schema::table('flights', function (Blueprint $table) {
                $table->dropColumn([
                    'is_open',
                    'open_class_count',
                    'min_price',
                    'min_capacity',
                    'flight_score',
                    'next_check_at',
                    'status_checked_at',
                ]);
            });
        });
    }
};
