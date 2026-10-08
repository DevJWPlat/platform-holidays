<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_schedule_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_schedule_id')->constrained()->cascadeOnDelete();
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_schedule_assignments');
    }
};
