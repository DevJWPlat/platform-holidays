<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organisations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('timezone')->default('Europe/London');
            $table->unsignedTinyInteger('leave_year_start_month')->default(1);
            $table->unsignedTinyInteger('leave_year_start_day')->default(1);
            $table->string('default_bank_holiday_division')->default('england-and-wales');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organisations');
    }
};
