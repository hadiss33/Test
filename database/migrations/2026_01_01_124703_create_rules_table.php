<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_class_id')
                ->constrained('flight_classes')
                ->onDelete('cascade');
            
            $table->text('refund_rules')->nullable();
            $table->integer('percent')->nullable();
            
            $table->unique(['flight_class_id', 'percent'], 'unique_class_percent');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rules');
    }
};