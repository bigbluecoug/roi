<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('territory_plans', function (Blueprint $table) {
            $table->id();
            $table->string('account_email')->unique();
            $table->json('planning_state')->nullable();
            $table->json('focused_state_codes')->nullable();
            $table->json('planner_settings')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('territory_plans');
    }
};
