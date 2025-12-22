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
        Schema::create('application_interfaces', function (Blueprint $table) {
            $table->id();

            $table->unsignedInteger('branch')->index(); 
            $table->string('type', 20);         
            $table->string('service', 50);     
            $table->string('url');
            $table->string('username')->nullable();
            $table->string('password')->nullable();
            
            $table->json('data')->nullable(); 
            $table->boolean('status')->default(1); 
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('application_interfaces');
    }
};
