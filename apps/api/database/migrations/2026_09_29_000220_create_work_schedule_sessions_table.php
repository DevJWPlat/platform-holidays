<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_schedule_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_schedule_id')->constrained()->cascadeOnDelete();

            $table->unsignedTinyInteger('week_number')->default(1);
            $table->unsignedTinyInteger('weekday');
            $table->string('session');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->unsignedInteger('duration_minutes');

            $table->timestamps();

            $table->unique([
                'work_schedule_id',
                'week_number',
                'weekday',
                'session',
            ], 'work_schedule_session_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_schedule_sessions');
    }
};
