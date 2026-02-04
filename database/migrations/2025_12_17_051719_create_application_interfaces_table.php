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
            $table->bigInteger('id', true);
            $table->bigInteger('branch');
            $table->enum('type', ['recaptcha', 'sms', 'ami', 'ftp', 'support', 'api', 'smtp', 'drive']);
            $table->enum('service', ['airplus', 'google', 'gmini', 'irnoti', 'issabel', 'goftino', 'navasan', 'ravis', 'sepehr', 'nira', 'liara', 'tport', 'sepehr_hotel', 'jibit', 'snapptrip_hotel','snapptrip_flight']);
            $table->enum('object_type', ['colleague'])->nullable();
            $table->bigInteger('object')->nullable();
            $table->string('url');
            $table->string('username');
            $table->string('password');
            $table->longText('data')->nullable();
            $table->integer('priority')->nullable();
            $table->integer('status')->default(1);

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
