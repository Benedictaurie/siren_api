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
        Schema::create('building_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('building_id')->constrained('buildings')->onDelete('cascade');
            $table->enum('category', ['commercial', 'social', 'all']);
            $table->decimal('price', 12, 2);
            $table->integer('min_hours')->nullable();
            $table->integer('max_hours')->nullable();
            $table->string('duration_type')->default('per_day'); //per_day, hourly_tier
            $table->string('note')->nullable(); //misal 6 sd/24 jam atau 1 s/d 5 jam
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('building_rates');
    }
};
