<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('districts', function (Blueprint $table) {
            $table->id();
            $table->string('state_code', 12)->index();
            $table->string('state_name')->nullable();
            $table->string('lea_id')->nullable();
            $table->string('name');
            $table->string('short_name')->nullable();
            $table->string('nces_name')->nullable();
            $table->string('city')->nullable();
            $table->string('lea_type')->nullable();
            $table->unsignedInteger('total_students')->default(0);
            $table->unsignedInteger('secondary_students')->default(0);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->text('search_text')->nullable();
            $table->timestamps();

            $table->unique(['state_code', 'lea_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('districts');
    }
};
